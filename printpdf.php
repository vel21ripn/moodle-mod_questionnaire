<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

require_once("../../config.php");
require_once($CFG->dirroot.'/mod/questionnaire/questionnaire.class.php');
require_once($CFG->dirroot.'/mod/questionnaire/NameCaseLib/Library/NCLNameCaseRu.php');

$qid = required_param('qid', PARAM_INT);
$rid = required_param('rid', PARAM_INT);
$courseid = required_param('courseid', PARAM_INT);
$hash = required_param('hash', PARAM_RAW);
$null = null;

if (! $questionnaire = $DB->get_record("questionnaire", array("id" => $qid))) {
    print_error('invalidcoursemodule');
}
if (! $course = $DB->get_record("course", array("id" => $questionnaire->course))) {
    print_error('coursemisconf');
}
if (! $cm = get_coursemodule_from_instance("questionnaire", $questionnaire->id, $course->id)) {
    print_error('invalidcoursemodule');
}

// Check login and get context.
require_login($courseid);

$questionnaire = new questionnaire(0, $questionnaire, $course, $cm);
// If you can't view the questionnaire, or can't view a specified response, error out.
if (!($questionnaire->capabilities->view && (($rid == 0) || $questionnaire->can_view_response($rid)))) {
    // Should never happen, unless called directly by a snoop...
    print_error('nopermissions', 'moodle', $CFG->wwwroot.'/mod/questionnaire/view.php?id='.$cm->id);
}
#if(!isset($questionnaire->survey->end_doc) || !$questionnaire->survey->end_doc) {
#    print_error('nofiles', 'questionnaire', $CFG->wwwroot.'/mod/questionnaire/view.php?id='.$cm->id);
#}

$rdata = new stdClass;
$questionnaire->add_questions($rid);

$context = context_module::instance($cm->id);
$fs = get_file_storage();
$files = $fs->get_area_files(
	$context->id,'mod_questionnaire','end_doc',0 & $questionnaire->survey->end_doc);

foreach($files as $xfile) {
	if($xfile->is_directory()) continue;
	if($xfile->get_contenthash() != $hash) continue;
	if(0) pre_print_r([$xfile->get_filename(), $xfile->get_filesize(), $xfile->get_mimetype(), $xfile->get_contenthash() ]);
	$a_name = preg_replace('/\.[a-z]+$/u','',basename($xfile->get_filename()));
	$fname = $CFG->dataroot.'/filedir/'.substr($hash,0,2).'/'.substr($hash,2,2).'/'.$hash;

	if($xfile->get_mimetype() != 'application/vnd.oasis.opendocument.text') {
		print_error('not_odt', 'questionnaire', $CFG->wwwroot.'/mod/questionnaire/view.php?id='.$cm->id);
	}
	send_template_file($context,$questionnaire,$fs,$xfile,$fname,$a_name,$hash,$rid);
	die;
}
send_file_not_found();
die;

function qa_cache_dir() {
        global $CFG;
        $cachedir = $CFG->dataroot.'/mod_questionnaire';
        if(!is_dir($cachedir)) {
            if(!mkdir($cachedir,0775))
                throw new \Exception("Cant create $cachedir");
        }
        return $cachedir;
}

function add_user_info(&$answers,$user) {

    $answers['USERNAME'] = $user->username;
    $answers['EMAIL'] = $user->email;
    $fio = trim($user->lastname.' '.$user->firstname);
    $fio = preg_replace('/\s\s+/',' ',$fio);
    $fio = preg_replace('/-\s+-$/','',$fio);
    $fio = preg_replace('/\s*-$/','',$fio);
    $nc = new NCLNameCaseRu();
    $nc_fio = $nc->q($fio);
    $answers['FIO'] = $fio;
    $answers['FIO1'] = $nc_fio[1]; 
    $answers['LNAME'] = $user->lastname;
    $answers['LNAME1'] = $nc->q($user->lastname)[1];
    $fio_p = preg_split('/\s+/',$fio);
    $firstname = $fio_p[1] ? $fio_p[1]:'';
    $answers['FNAME'] = $firstname;
    $answers['FNAME1'] = $nc->q($firstname)[1];
    $answers['FNAME_I'] = mb_substr(mb_strtoupper($firstname),0,1);
    if($answers['FNAME_I']) $answers['FNAME_I'] .= '.';
    $middlename = $fio_p[2] ? $fio_p[2]:'';
    $answers['MNAME'] = $middlename;
    $answers['MNAME1'] = $nc->q($middlename)[1];
    $answers['MNAME_I'] = mb_substr(mb_strtoupper($middlename),0,1);
    if($answers['MNAME_I']) $answers['MNAME_I'] .= '.';
    $answers['FIO_I'] = $answers['LNAME'].' '.$answers['FNAME_I'].$answers['MNAME_I'];
    $answers['CDATA'] = strftime("%d.%m.%Y",time());
}

function replace_content($answers,$content) {
$ret = '';

$cl = strlen($content);
$st = 0;
$var  = '';
for($i=0; $i < $cl; $i++) {
	$c = $content[$i];
	if(!$st) {
	   if($c == '[' && $content[$i+1] == '[') {
	   	$st = 1;
		$i++;
		$var = '';
		continue;
	   }
	   $ret .= $c;
	} else {
	    if($c == ']' && $content[$i+1] == ']') {
		if(isset($answers[$var])) {
		    $ret .= $answers[$var];
#		} else {
#		    $ret .= '???????';
		}
	   	$st = 0;
		$i++;
		continue;
	    }
	    $var .= $c;
	}
}
return $ret;
}

function send_template_file($context,$questionnaire,$fs,$xfile,$fname,$name,$hash,$rid) {
global $CFG,$USER,$DB;

$t_dir = qa_cache_dir();

$resp = $DB->get_record('questionnaire_response',['id'=>$rid]);
$user = $DB->get_record('user',['id'=>$resp->userid]);

$answers = [];
foreach ($questionnaire->get_structured_response($rid) as $q) {
    $qt = preg_replace('/^\s*[0-9]+\.\s+/u','',$q->questionname);
    $qt = preg_replace('/[^0-9A-ZА-Я]/iu','_',$qt);
    if($q->type == 'Date') {
	$d = $q->answers[0];
	if(preg_match('/^(\d\d\d\d)-(\d\d)-(\d\d)$/',$d,$m))
		$d = $m[3].'.'.$m[2].'.'.$m[1];
	$answers[$qt] = $d;
    } else {
	$answers[$qt] = implode(',',$q->answers);
    }
}
add_user_info($answers,$user);

$file = $fs->get_file($context->id,'mod_questionnaire','end_doc',0 & $questionnaire->survey->end_doc,
	'/',$xfile->get_filename());

if($file) {
	$t_name = $t_dir.'/'.$rid.'_'.$hash;
	clearstatcache(false,$t_name.'.odt');
	if(file_exists($t_name.'.odt')) unlink($t_name.'.odt');
	$out = 'Success';
	do {
		$file->copy_content_to($t_name.'.odt');
		if(!file_exists($t_name.'.odt')) {
			$out = "copy failed";
			break;
		}
		$fileToModify='content.xml';
		$zip = new ZipArchive;
		if ($zip->open($t_name.'.odt') === TRUE) {
		    $oldContents = $zip->getFromName($fileToModify);
		    #pre_print_r($answers);
		    $newContents = replace_content($answers, $oldContents, $user);
		    #echo $newContents;
		    #die;
		    $zip->deleteName($fileToModify);
		    $zip->addFromString($fileToModify, $newContents);
		    $zip->close();
		} else {
		    $out = "open zip failed";
		    break;
		}
		if(file_exists($t_name.'.pdf')) unlink($t_name.'.pdf');
		clearstatcache(false,$t_name.'.pdf');
		$out = shell_exec("/usr/bin/python3 /usr/local/bin/unoconv --connection 'socket,host=127.0.0.1,port=10001,tcpNoDelay=1;urp;StarOffice.ComponentContext' -f pdf $t_name.odt");
		if(file_exists($t_name.'.pdf'))
			send_file($t_name.'.pdf',$name.'.pdf',-1);
		$out .= " No output file";
	} while(false);
	pre_print_r([$t_name,$out]);
} else {
	send_file_not_found();
}
die;
}

