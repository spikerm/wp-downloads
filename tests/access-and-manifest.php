<?php
define('ABSPATH',__DIR__);
class WP_Error {public $message;public function __construct($key,$message){$this->message=$message;}}
function is_wp_error($value){return $value instanceof WP_Error;}
function sanitize_key($value){return preg_replace('/[^a-z0-9_\-]/','',strtolower($value));}
function wp_roles(){return (object)['ID'=>1,'roles'=>['subscriber'=>[],'rex_tech'=>[]]];}
function get_option($key,$default=[]){return $GLOBALS['settings']??$default;}
function get_post_meta($id,$key,$single){return $GLOBALS['postmeta'][$key]??'';}
$meta=[1=>['_rex_access_mode'=>'custom','_rex_roles'=>['rex_tech']],3=>['_rex_access_mode'=>'custom','_rex_roles'=>[]]];
function get_term_meta($id,$key,$single){return $GLOBALS['meta'][$id][$key]??'';}
function get_term($id,$taxonomy){return (object)['term_id'=>$id,'parent'=>$id===2?1:0];}
function wp_get_object_terms($id,$taxonomy,$args){return $id===20?[]:($id===30?[3]:[2]);}
$roles=['subscriber'];
function wp_get_current_user(){return (object)['ID'=>1,'roles'=>$GLOBALS['roles']];}
require __DIR__.'/../rex-downloads/features.php';
require __DIR__.'/../rex-downloads/migration.php';
function verify($condition,$message){if(!$condition){throw new Exception($message);}}
verify(!Rex_Downloads_Features::category_access(10),'Subscriber may not access inherited tech category');
$roles=['rex_tech'];verify(Rex_Downloads_Features::category_access(10),'Inherited role should allow');
verify(!Rex_Downloads_Features::category_access(20),'Uncategorized inherited files must be denied');
verify(!Rex_Downloads_Features::category_access(30),'Empty category roles must deny');
$meta[3]['_rex_roles']=['*'];verify(Rex_Downloads_Features::category_access(30),'All logged-in users marker must survive sanitizing');
verify(Rex_Downloads_Features::effective_category_roles(4)===['*'],'Root without category policy defaults to logged in');
verify(Rex_Downloads_Features::roles(['@guest','*','unknown'])===['@guest','*'],'Public marker is preserved');
$guest=(object)['ID'=>0,'roles'=>[]];$settings=['guest_access'=>0];$meta[1]['_rex_roles']=['@guest'];
verify(!Rex_Downloads_Features::category_access(10,$guest),'Global switch blocks guest');$settings['guest_access']=1;
verify(Rex_Downloads_Features::category_access(10,$guest),'Inherited public access for guests');
$meta[1]['_rex_roles']=['*'];verify(!Rex_Downloads_Features::category_access(10,$guest),'Logged-in marker cannot allow guests');
$postmeta=['_rex_roles'=>['@guest']];verify(Rex_Downloads_Features::guest_access(1),'Explicit public file');
$postmeta=['_rex_roles'=>['*']];verify(!Rex_Downloads_Features::guest_access(1),'Explicit logged-in file stays private');
$m=['format'=>'wp-dares-downloads','schema'=>1,'categories'=>[['id'=>1,'parent'=>0,'name'=>'Main'],['id'=>2,'parent'=>1,'name'=>'Sub']],'downloads'=>[['uuid'=>'a1234567-1234-1234-1234-123456789012','entry'=>'files/'.str_repeat('a',48).'.bin','size'=>10,'sha256'=>str_repeat('a',64),'title'=>'Manual','description'=>'','categories'=>[2],'meta'=>['name'=>'manual.pdf']]]];
verify(Rex_Downloads_Migration::validate_manifest($m)===true,'Valid manifest accepted');
$bad=$m;$bad['downloads'][0]['entry']='../config.php';verify(is_wp_error(Rex_Downloads_Migration::validate_manifest($bad)),'Reject traversal');
$bad=$m;$bad['categories'][0]['parent']=2;verify(is_wp_error(Rex_Downloads_Migration::validate_manifest($bad)),'Reject category cycles');
$bad=$m;$bad['downloads'][0]['size']=536870913;verify(is_wp_error(Rex_Downloads_Migration::validate_manifest($bad)),'Reject oversized archive');
$bad=$m;$bad['downloads'][0]['meta']['name']='payload.php';verify(is_wp_error(Rex_Downloads_Migration::validate_manifest($bad)),'Reject executable extension');
$bad=$m;$bad['downloads'][]=$bad['downloads'][0];verify(is_wp_error(Rex_Downloads_Migration::validate_manifest($bad)),'Reject duplicate UUID');
echo "Category inheritance and migration validation passed.\n";
