<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/admin-tts-helpers.php';
$db = getDb();
$audioKey = 289;
$a = $db->query("SELECT chapter_key, volume_key FROM yy_tts_audio WHERE tts_audio_key=$audioKey")->fetch(PDO::FETCH_ASSOC);
$chapterKey=(int)$a['chapter_key']; $volumeKey=(int)$a['volume_key'];

$sr=$db->prepare("SELECT volume_skip_pages FROM yy_volume WHERE volume_key=?"); $sr->execute([$volumeKey]);
$skipRanges=[];
foreach (preg_split('/\s*,\s*/', (string)($sr->fetchColumn()?:''), -1, PREG_SPLIT_NO_EMPTY) as $tok){
  if(preg_match('/^\s*(\d+)\s*-\s*(\d+)\s*$/',$tok,$m))$skipRanges[]=[(int)$m[1],(int)$m[2]];
  elseif(preg_match('/^\s*(\d+)\s*$/',$tok,$m))$skipRanges[]=[(int)$m[1],(int)$m[1]];
}
$inSkip=function(?int $pg)use($skipRanges){ if($pg===null)return false; foreach($skipRanges as $r) if($pg>=$r[0]&&$pg<=$r[1])return true; return false; };
$bm = $volumeKey ? ttsBackMatterCutoff($db,$volumeKey) : null;
$isBM=function(int $n)use($bm){ return $bm!==null && $n>=$bm; };

$rows=$db->prepare("SELECT paragraph_number,paragraph_page,paragraph_text_plain,paragraph_is_table,paragraph_is_continuation
   FROM yy_paragraph WHERE chapter_key=? ORDER BY paragraph_number");
$rows->execute([$chapterKey]); $rows=$rows->fetchAll(PDO::FETCH_ASSOC);

$widx=0; $map=[];
foreach($rows as $r){
  $num=(int)$r['paragraph_number']; $pg=$r['paragraph_page']!==null?(int)$r['paragraph_page']:null;
  if(!empty($r['paragraph_is_continuation'])) continue;
  if(!empty($r['paragraph_is_table'])||$inSkip($pg)||$isBM($num)) continue;
  $map[$widx]=[$num,$pg,preg_replace('/\s+/u',' ',mb_substr((string)$r['paragraph_text_plain'],0,75))];
  $widx++;
}
echo "back_matter_cutoff=".var_export($bm,true)."  total_parts=$widx\n\n";
for($i=94;$i<=102;$i++){ if(isset($map[$i])) printf("p%05d  p#%-5d page=%-4s :: %s\n",$i,$map[$i][0],$map[$i][1]??'-',$map[$i][2]); }
