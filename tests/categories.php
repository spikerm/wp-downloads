<?php
define('ABSPATH',__DIR__);
function add_action(...$args){} function add_shortcode(...$args){} function add_filter(...$args){}
function plugin_basename($file){return 'rex-downloads/rex-downloads.php';}
function current_user_can(...$args){return $GLOBALS['authorized'];}
function check_admin_referer($action){if(!$GLOBALS['nonce']){throw new Exception('nonce');}}
function wp_die($message,...$args){throw new Exception($message);}
function absint($value){return abs((int)$value);}
function wp_unslash($value){return $value;}
function sanitize_text_field($value){return strip_tags($value);}
function term_exists($id,$taxonomy){return in_array($id,[1,2,3,4],true);}
function get_term($id,$taxonomy){return term_exists($id,$taxonomy) ? (object)['term_id'=>$id,'parent'=>0] : null;}
function get_term_children($id,$taxonomy){return $id===1 ? [2,3] : [];}
function is_wp_error($value){return false;}
function wp_update_term($id,$taxonomy,$args){$GLOBALS['saved']=[$id,$args];return ['term_id'=>$id];}
function admin_url($value){return $value;}
function wp_safe_redirect($url){throw new Exception('redirect');}
require __DIR__.'/../rex-downloads/rex-downloads.php';
$app=new Rex_Downloads();$authorized=true;$nonce=true;$saved=null;
foreach ([1,2,3] as $parent){
 $_POST=['category_name'=>'Test','category_id'=>1,'category_parent'=>$parent];
 try{$app->save_category();throw new Exception('Cycle was accepted');}catch(Exception $e){if(strpos($e->getMessage(),'eigen subcategorie')===false){throw $e;}}
 if($saved!==null){throw new Exception('Cycle saved');}
}
$_POST=['category_name'=>'Nieuwe naam','category_id'=>1,'category_parent'=>4];
try{$app->save_category();}catch(Exception $e){if($e->getMessage()!=='redirect'){throw $e;}}
if($saved!==[1,['name'=>'Nieuwe naam','parent'=>4]]){throw new Exception('Reparenting failed');}
$authorized=false;
try{$app->save_category();throw new Exception('Permission bypass');}catch(Exception $e){if($e->getMessage()!=='Geen toegang.'){throw $e;}}
$authorized=true;$nonce=false;
try{$app->save_category();throw new Exception('Nonce bypass');}catch(Exception $e){if($e->getMessage()!=='nonce'){throw $e;}}
echo "Category permissions and cycle checks passed.\n";
