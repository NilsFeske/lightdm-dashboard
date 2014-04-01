<?php
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

date_default_timezone_set('Europe/Berlin');
ini_set('default_socket_timeout', 2);

function file_get_contents_post($url, $post_data) {
    $options = array('http' => array(
        'header' => "Content-type: application/x-www-form-urlencoded\r\n",
        'method' => 'POST',
        'content' => $post_data
    ));
    $context = stream_context_create($options);
    return file_get_contents($url, false, $context);
}

function decode_entities($text) {
    $text= html_entity_decode($text,ENT_QUOTES,"ISO-8859-1"); #NOTE: UTF-8 does not work!
    $text= preg_replace('/&#(\d+);/me',"chr(\\1)",$text); #decimal notation
    $text= preg_replace('/&#x([a-f0-9]+);/mei',"chr(0x\\1)",$text);  #hex notation
    return $text;
}

$bsag_url = "http://62.206.133.180/bsag/XSLT_DM_REQUEST";
$bsag_post = array(
    'command'   => '',
    'deleteAssignedStops_dm' => 1,
    'itdDateDay' => date('d'),
    'itdDateMonth' => date('m'),
    'itdDateYear' => date('Y'),
    'itdLPxx_id_dm' => ':dm',
    'itdLPxx_mapState_dm' => '',
    'itdLPxx_mdvMap2_dm' => '',
    'itdLPxx_mdvMap_dm' => '3406199:401077:NAV3',
    'itdLPxx_transpCompany' => 'bsag',
    'itdLPxx_view' => '',
    'itdTimeHour' => date('H'),
    'itdTimeMinute' => date('i'),
    'language'  => 'de',
    'nameInfo_dm' => 'invalid',
    'nameState_dm' => 'empty',
    'name_dm' => utf8_decode('Universität/Zentralbereich'),
    'placeInfo_dm' => 'invalid',
    'placeState_dm' => 'empty',
    'place_dm' => 'Bremen',
    'ptOptionsActive' => 1,
    'requestID' => 0,
    'sessionID' => 0,
    'submitButton' => 'anfordern',
    'typeInfo_dm' => 'invalid',
    'type_dm' => 'stop',
    'useProxFootSearch' => 0,
    'useRealtime' => 1,
);

$bsag_result = file_get_contents_post($bsag_url, http_build_query($bsag_post));
$dom = new DOMDocument;
$dom->loadHTML($bsag_result);
$bsag_post = array();
foreach($dom->getElementsByTagName('input') as $node) {
    $type = $node->getAttribute('type');
    if($type == 'hidden' || $type == 'submit')
        $bsag_post[$node->getAttribute('name')] = $node->getAttribute('value');
}
# lines to fetch, get values by manual inspecting the site
$bsag_post['dmLineSelection'] = array('4:1', '4:2', '5:1', '5:3', '5:4', '5:6');
$bsag_content = preg_replace('#%5B\d+%5D#', '', http_build_query($bsag_post));
$bsag_result = file_get_contents_post($bsag_url, $bsag_content);
$dom->loadHTML($bsag_result);
$xpath = new DOMXPath($dom);
$tables = $xpath->query('//table//table//table[count(.//tr)>=4]');
$connections = array();
$now = time();
if($tables->length == 1) {
    $table = $tables->item(0);
    foreach($xpath->query('./tr', $table) as $tr) {
        if(strncmp($tr->getAttribute('class'), 'bgColor', 7))
            continue;
        $tds = $xpath->query('./td', $tr);
        $time = strtotime($tds->item(1)->nodeValue);
        if($time < $now)
            continue;
        $line = $tds->item(4)->nodeValue;
        $type = strtolower($xpath->query('./img/@alt', $tds->item(3))->item(0)->nodeValue);
        if(!array_key_exists($line, $connections)) {
            $connections[$line] = array('line' => $line, 'type' => $type, 'connections' => array());
        }
        $connections[$line]['connections'][] = $time;
    }
}
ksort($connections);
include "stdwerk-bremen/mensa_new.php";
include "stdwerk-bremen/mensa_new_config.php";

$mensajson = get_mensa_json($mensa_cache_file);

if (!$mensajson)
  $mensajson = refresh_mensa($mensa_url, $mensa_match, $mensa_cache_file);

$mensa = json_decode($mensajson, true);
$searchstamp = date('H') >= 14? strtotime('+1 day') : time();
$searchdate = date('d.m.Y', $searchstamp);
$index = @array_search($searchdate, $mensa['datum']['v']);
if($index === FALSE || $index == NULL)
  $mensa = array('date' => $searchstamp, 'dishes' => array());
else {
  $beilagen = explode(' |,| ', $mensa['beilagen']['v'][$index]);
  $beilagen = array_filter($beilagen, create_function('$v', 'return substr($v, 0, 13) != "Pommes frites" && substr($v, 0, 12) != "Baked potato";'));
  $beilagen = implode(', ', $beilagen);
  $mensa = array('date' => $searchstamp, 'dishes' => array(
    array('name' => 'Essen 1', 'meal' => $mensa['essen1']['v'][$index]),
    array('name' => 'Essen 2', 'meal' => $mensa['essen2']['v'][$index]),
    array('name' => 'Vege&shy;tarisch', 'meal' => $mensa['vegetarisch']['v'][$index]),
    array('name' => 'Wok &amp; Pfanne', 'meal' => $mensa['wok']['v'][$index]),
    array('name' => 'Beilagen', 'meal' => $beilagen),
  ));
}

$gw2json = get_mensa_json($gw2_cache_file);

if (!$gw2json)
  $gw2json = refresh_mensa($gw2_url, $gw2_match, $gw2_cache_file);

$gw2 = json_decode($gw2json, true);
$searchstamp = date('Hi') >= 1430? strtotime('+1 day') : time();
$searchdate = date('d.m.Y', $searchstamp);
$index = array_search($searchdate, $gw2['datum']['v']);
if($index === FALSE || $index == NULL)
  $gw2 = array('date' => $searchstamp, 'dishes' => array());
else {
  $gw2 = array('date' => $searchstamp, 'dishes' => array(
    array('name' => 'Pizza', 'meal' => $gw2['pizza']['v'][$index]),
    array('name' => 'Pasta', 'meal' => $gw2['pasta']['v'][$index]),
    array('name' => 'Front Cooking', 'meal' => $gw2['frontcooking']['v'][$index]),
    array('name' => 'Suppe', 'meal' => $gw2['suppe']['v'][$index]),
  ));
}

$tmp = file_get_contents('https://m.xkcd.com/');
preg_match('/<img id="comic" (?:(?:alt="(?P<alt>[^"]*)"|src="(?P<src>[^"]+)"|title="(?P<title>[^"]*)"|[^ ]+) )+\/>/', $tmp, $xkcd);
$xkcd = array_map("decode_entities", $xkcd);
unset($tmp);

echo json_encode(array(
    'bsag' => array_values($connections),
    'mensa' => $mensa,
    'gw2' => $gw2,
    'xkcd' => $xkcd,
/* not yet implemented
    'calendar' => array(
        array('name' => 'Mathe', 'events' => array(
            array('start' => 1374690700, 'end' => 1374690760, 'title' => 'Test-Event'),
            array('start' => 1374790700, 'end' => 1374790760, 'title' => 'Test-Event 2'),
        )),
        array('name' => 'Informatik', 'events' => array(
            array('start' => 1375690700, 'end' => 1375690760, 'title' => 'Inf-Event'),
        )),
        array('name' => 'Geburtstage', 'events' => array(
            array('start' => strtotime('9. April 2014 00:00:00'), 'end' => strtotime('09. April 2014 23:59:59'), 'title' => 'JP'),
        )),
    ),
*/
));
