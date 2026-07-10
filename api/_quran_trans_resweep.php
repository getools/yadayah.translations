<?php
/**
 * One-off: re-synth already-built TTS paragraphs that are Quran-translation
 * lines (Yusuf Ali / Pickthal / Shakir / Ahmed Ali / Noble Quran / Word-by-
 * Word), now that the build worker's Quran-translation prepass routes each to
 * its per-translator child voice. Detection MIRRORS the worker prepass
 * exactly (run of >=2 translator lines over the coalesced+skip-filtered
 * sequence). For each affected audio row: delete the affected paragraphs'
 * POSITIONAL part files (p%05d.mp3) so they re-synth with the new voice; for
 * 'complete' rows also flip to 'pending' so the watchdog rebuilds. 'pending'/
 * 'paused' rows just get parts deleted (they pick up the new voice on build).
 * 'running' rows are skipped (never delete parts under a live worker).
 *
 *   php _quran_trans_resweep.php            # dry-run
 *   php _quran_trans_resweep.php --apply
 *   php _quran_trans_resweep.php --ak=NNN [--apply]
 */
require_once __DIR__ . '/config.php';

$db = getDb();
$apply = in_array('--apply', $argv, true);
$onlyAk = null;
foreach ($argv as $a) if (preg_match('/^--ak=(\d+)$/', $a, $m)) $onlyAk = (int)$m[1];

// Prefer the host parts dir if this process can see it (matches where the
// worker writes); fall back to the container public path. Verified below.
$candidates = ['/opt/yada-www/public', dirname(__DIR__) . '/public', dirname(__DIR__)];
$audioBase = null;
foreach ($candidates as $c) if (is_dir($c . '/u/tts-parts')) { $audioBase = $c; break; }
if ($audioBase === null) $audioBase = '/opt/yada-www/public';
fwrite(STDERR, "parts base: $audioBase/u/tts-parts\n");

// Closed translator dictionary — identical to the worker prepass.
$QT = ['Yusuf Ali'=>'yusuf_ali','Noble Quran'=>'noble_quran','Pickthal'=>'pickthal','Shakir'=>'shakir','Ahmed Ali'=>'ahmed_ali'];
$alt = implode('|', array_map(fn($n)=>preg_quote($n,'/'), array_keys($QT)));
$qtCat = function(string $plain) use ($alt,$QT): ?string {
    if (preg_match('/^\s*(?:The\s+)?('.$alt.')\s*:/u',$plain,$m)) return $QT[$m[1]];
    if (preg_match('/^\s*(?:The\s+)?(?:Quran\s+)?Word[\s-]?by[\s-]?Word\b[^:]{0,40}:/ui',$plain)) return 'word_by_word';
    return null;
};

$sql = "SELECT tts_audio_key, chapter_key, volume_key, tts_audio_status
          FROM yy_tts_audio
         WHERE tts_audio_active_flag AND tts_audio_status <> 'running'
           AND tts_audio_status IN ('complete','paused','pending')";
if ($onlyAk) $sql .= " AND tts_audio_key = " . $onlyAk;
$sql .= " ORDER BY tts_audio_key";
$rows = $db->query($sql)->fetchAll(PDO::FETCH_ASSOC);

$totAudio=0; $totParts=0; $reflagged=0; $other=0;
foreach ($rows as $a) {
    $audioKey=(int)$a['tts_audio_key']; $chapterKey=(int)$a['chapter_key'];
    $volumeKey=(int)$a['volume_key']; $status=$a['tts_audio_status'];

    $skipRanges=[];
    if ($volumeKey) {
        $sr=$db->prepare("SELECT volume_skip_pages FROM yy_volume WHERE volume_key=?"); $sr->execute([$volumeKey]);
        foreach (preg_split('/\s*,\s*/', (string)($sr->fetchColumn()?:''), -1, PREG_SPLIT_NO_EMPTY) as $tok) {
            if (preg_match('/^\s*(\d+)\s*-\s*(\d+)\s*$/',$tok,$m)) $skipRanges[]=[(int)$m[1],(int)$m[2]];
            elseif (preg_match('/^\s*(\d+)\s*$/',$tok,$m)) $skipRanges[]=[(int)$m[1],(int)$m[1]];
        }
    }
    $inSkip=function(?int $pg) use ($skipRanges){ if($pg===null)return false; foreach($skipRanges as $r) if($pg>=$r[0]&&$pg<=$r[1])return true; return false; };

    $pst=$db->prepare("SELECT paragraph_number,paragraph_page,paragraph_text_plain,paragraph_is_table,paragraph_is_continuation
                       FROM yy_paragraph WHERE chapter_key=? ORDER BY paragraph_number");
    $pst->execute([$chapterKey]); $prows=$pst->fetchAll(PDO::FETCH_ASSOC);

    // widx map — EXACT copy of admin-tts-build.php part-index pass.
    $playIdxByNum=[]; $widx=0; $lastHeadIdx=null;
    foreach ($prows as $r) {
        $num=(int)$r['paragraph_number']; $pg=$r['paragraph_page']!==null?(int)$r['paragraph_page']:null;
        if (!empty($r['paragraph_is_continuation'])) { if($lastHeadIdx!==null)$playIdxByNum[$num]=$lastHeadIdx; continue; }
        if (!empty($r['paragraph_is_table']) || $inSkip($pg)) continue;
        $playIdxByNum[$num]=$widx; $lastHeadIdx=$widx; $widx++;
    }

    // Block detection over the SAME sequence the worker prepass sees:
    // coalesced (continuations absorbed) + skip/table filtered.
    $seq=[];
    foreach ($prows as $r) {
        if (!empty($r['paragraph_is_continuation'])) continue;
        $pg=$r['paragraph_page']!==null?(int)$r['paragraph_page']:null;
        if (!empty($r['paragraph_is_table']) || $inSkip($pg)) continue;
        $seq[]=$r;
    }
    $affNums=[]; $run=[];
    $flush=function() use (&$run,&$affNums){ if(count($run)>=2) foreach($run as $n)$affNums[$n]=true; $run=[]; };
    foreach ($seq as $r) {
        $c=$qtCat(trim((string)$r['paragraph_text_plain']));
        if ($c!==null) { $run[]=(int)$r['paragraph_number']; continue; }
        $flush();
    }
    $flush();
    if (!$affNums) continue;

    $affIdx=[]; foreach (array_keys($affNums) as $num) if(isset($playIdxByNum[$num])) $affIdx[$playIdxByNum[$num]]=true;

    $partsDir=$audioBase.'/u/tts-parts/'.$audioKey;
    $present=[]; $absent=0;
    foreach (array_keys($affIdx) as $idx){ $pf=$partsDir.sprintf('/p%05d.mp3',$idx); if(is_file($pf))$present[]=$idx; else $absent++; }

    printf("ak=%-5d st=%-8s chap=%-6d vol=%-3d trans_lines=%-3d part_del=%-3d absent=%d\n",
           $audioKey,$status,$chapterKey,$volumeKey,count($affNums),count($present),$absent);
    $totAudio++; $totParts+=count($present);

    if ($apply) {
        foreach ($present as $idx) @unlink($partsDir.sprintf('/p%05d.mp3',$idx));
        if ($status==='complete') {
            $db->prepare("UPDATE yy_tts_audio SET tts_audio_status='pending', tts_audio_worker_pid=NULL,
                             tts_audio_message='re-queued: Quran per-translator voices'
                           WHERE tts_audio_key=? AND tts_audio_status='complete'")->execute([$audioKey]);
            $reflagged++;
        } else $other++;
    }
}
printf("\n%s: %d audio rows, %d stale parts %s; reflagged(complete)=%d, parts-only(pending/paused)=%d\n",
       $apply?'APPLIED':'DRY-RUN', $totAudio, $totParts, $apply?'deleted':'would delete', $reflagged, $other);
