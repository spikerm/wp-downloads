<?php
define('ABSPATH',__DIR__);
function add_action(...$args){} function add_shortcode(...$args){} function add_filter(...$args){}
function plugin_basename($file){return 'rex-downloads/rex-downloads.php';}
function current_user_can(...$args){return true;} function check_admin_referer($value){}
function absint($value){return abs((int)$value);} function sanitize_key($value){return $value;} function wp_unslash($value){return $value;}
function is_wp_error($value){return false;}
function wp_die($message,...$args){throw new Exception($message);}
function admin_url($url){return $url;} function wp_safe_redirect($url){throw new Exception('redirect');}
$order=[10=>8];
$terms=[(object)['term_id'=>1,'parent'=>0,'name'=>'Alpha'],(object)['term_id'=>2,'parent'=>0,'name'=>'Beta'],(object)['term_id'=>3,'parent'=>0,'name'=>'Gamma'],(object)['term_id'=>10,'parent'=>1,'name'=>'Child']];
function get_option($key,$default){return $GLOBALS['order'];}
function update_option($key,$value,$autoload){$GLOBALS['order']=$value;}
function get_term($id,$taxonomy){foreach($GLOBALS['terms'] as $term){if($term->term_id===$id){return $term;}}return null;}
function get_terms($args){return array_values(array_filter($GLOBALS['terms'],function($term)use($args){return $term->parent===$args['parent'];}));}
require __DIR__.'/../rex-downloads/rex-downloads.php';
$app=new Rex_Downloads();
function move_category($id,$direction){global $app;$_POST=['category_id'=>$id,'direction'=>$direction];try{$app->order_category();}catch(Exception $e){if($e->getMessage()!=='redirect'){throw $e;}}}
move_category(2,'up');
if($order!==[10=>8,2=>0,1=>1,3=>2]){throw new Exception('Order or unrelated subcategory changed');}
$before=$order;move_category(2,'up');if($order!==$before){throw new Exception('Boundary move changed order');}
move_category(2,'down');if($order[1]!==0||$order[2]!==1||$order[3]!==2||$order[10]!==8){throw new Exception('Move down failed');}
echo "Category sibling order and boundary tests passed.\n";
