<?php
$last_error = null;
$template_stack=array();
class TemplateFrame{
    public $template; //must be a block
    public $current_placeholder;
    public $placeholders=array();
    public function __construct($template) {
        $this->template=$template ;
        $this->current_placeholder='body';
    }
};

class Block{
    //public $required_placeholders; //aray of required variables. empty for  non otional
    public $is_optional=false;
    public $elements; //array of items. each can be either block or string. string can prefixed with # or not
    public function __construct() {
        $this->elements=array();
        
    }
};
class Tokens{
    public $tokens;
    public $head=0;
    public function __construct($tokens) {
        $this->tokens=$tokens;
    }
    function read_token(){
        if ($this->head >= count($this->tokens))
            return null;
        return $this->tokens[$this->head++];
    }
}
function parse_block(&$tokens,$is_optional){
    $ans=new Block();
    $ans->is_optional=$is_optional;
    while(true){
        $token=$tokens->read_token();    
        if (!$token){
            if ($is_optional)
                do_throw('mismatching }');
            if (!count($ans->elements))
                do_throw('empty template');
            return $ans;
        }        
        if ($token=='{'){
            array_push($ans->elements,parse_block($tokens,true));
            continue;
        }
        if ($token=='}' & $is_optional)
            return $ans;
        array_push($ans->elements,$token);                
    }   
}
function parse_template($template){
    /*split
     */
    $content=file_get_contents($template);
    preg_match_all('/[^\{\}#%]*|#\w*|%\w*|\{|\}/', $content, $matches, PREG_PATTERN_ORDER);
    $tokens_paded=$matches[0];
    $tokens=array();
    foreach($tokens_paded as $token) //overcoming a quirq in preg_match_all
        if ($token!="")
            array_push ($tokens, $token); 
    return parse_block(new Tokens($tokens),false);
}
function is_placeaholder($element){
    $prefix=substr($element, 0,1);
    return is_string($element) && $prefix=='#';
}
function is_placeaholder_url_encoded($element){
    $prefix=substr($element, 0,1);
    return is_string($element) && $prefix=='%';
}
function process_block($frame,$block){
    $ans=array();
    foreach($block->elements as $element){
        if (is_placeaholder($element)){
            $value=get_index($frame->placeholders,  substr($element,1));
            if (!$value && $block->is_optional){
                return "";
            }
            array_push($ans,$value);
            continue;                
        }
        if (is_placeaholder_url_encoded($element)){
            $value=get_index($frame->placeholders,  substr($element,1));
            if (!$value && $block->is_optional){
                return "";
            }
            array_push($ans,  urlencode($value));
            continue;                
        }
        if (is_string($element)){
            array_push($ans,$element);
            continue;
        }
        //if reached here than it must be a block todo assert that?
        $block_result=process_block($frame,$element);
        array_push($ans,$block_result);        
    }
    return join("",$ans);
}
function fr_pop_template(){
    global $template_stack;
    if (count($template_stack)==0)
        return false;
    private_flush_current_placeholder();
    $frame=array_pop($template_stack);
    
    $top_block=parse_template($frame->template);
    $str=process_block($frame,$top_block); //gets printed to the current placeholder of the above stack or to the screen
    print($str);
    /*read the template from file, and produce an array of one of three types
     * text
     * placeholder
     * optional block - again, an array of the same
     * non optinal block
     * how to recognize the types? for now: text is a string, placeholder is a string that starts with '#', and an optional block is an array
     * next: write a function that compilers a templates (split_template)
     */
}
function fr_push_template($template){
    global $template_stack;
    array_push($template_stack,new TemplateFrame($template));
    fr_placeholder_printo("body"); // todo: parse the tempale at this stage and extraxt the default placeholder
}
function fr_exit(){
    throw new Exception('exit');
}
function fr_get_last_error_sqli($conn){
    return fr_get_last_error() . mysqli_error($conn);
}
function fr_int_param($name){
    return intval(fr_param($name,0));    
}
function fr_mandatory_param($name){
    $ans=fr_param($name,null);
    if ($ans==null)
        fr_redirect_and_exit("");
    return $ans;
}
function fr_override_values(&$a,$b){
    foreach($a as $key=>$value)
        $a[$key]=get_index($b,$key,$value);
}
function fr_redirect_and_exit($url){
    header("Location: $url", true, 303);    
    die();
}
function fr_print_template($file_name,$values,$values2){
    fr_override_values($values,$values2);
    fr_push_template($file_name);
    foreach($values as $key=>$value)
        fr_placeholder_set ($key, $value);
    fr_pop_template();    
}
function capture_error($errno, $err_string) {
    global $last_error;
    $last_error = $err_string;
}
function fr_get_last_error() {
    global $last_error;
    if (!$last_error)
        return null;
    $ans = $last_error;
    $last_error = null;
    return end(explode("):", $ans));
}
function fr_run(){
    set_error_handler("capture_error", E_WARNING); 
    $action='on_'.fr_param('action','default');
    try{
        fr_push_template('template.htm');
        $action();
    } catch (Exception $e) {
        print ("<pre>$e<?pre>");
    }
    while(fr_pop_template());
}
function toggle($value,$options){
    return $value==$options[0]?$options[1]:$options[0];
}
function compare($a,$b,$sort,$dir){
    $dir=($dir=="desc"?-1:1);
    $a_val=$a[$sort];
    $b_val=$b[$sort];
    if ($a_val==$b_val)
        return 0;
    if ($a_val>$b_val)
        return $dir;
    return -1*$dir;
}
function make_link($content,$values,$copy=array()){
    $href=make_href2($values,$copy);
    return "<a href=$href>$content</a>";    
}
function make_href2($replace,$copy_array=array()) {
    $request=array() ;
    foreach ($copy_array as $key)
        if (isset($_REQUEST[$key]))
            $request[$key]=$_REQUEST[$key];
    foreach ($replace as $key => $value) 
        $request[$key]=$value;
    return "index.php?".http_build_query($request);
}
function make_href($replace_name, $replace_value,$clear_array=array()) {
    return make_href2(array($replace_name=>$replace_value),$clear_array);
}
function fr_placeholder_append($name,$value){
    global $template_stack;
    end($template_stack)->placeholders[$name].=$value;//todo: check that the placeholder exists?
}
function fr_placeholder_set($name,$value){
    global $template_stack;
    end($template_stack)->placeholders[$name]=$value;//todo: check that the placeholder exists?
}
function redirect_and_exit($url="index.php") {
    header("Location: $url", true, 303);
    die();
}
function private_flush_current_placeholder(){
    global $template_stack;
    $value=ob_get_clean();
    $current_placeholder=end($template_stack)->current_placeholder;
    fr_placeholder_set($current_placeholder,$value);
}
function fr_placeholder_printo($placeholder){    
    global $template_stack;
    private_flush_current_placeholder();
    end($template_stack)->current_placeholder=$placeholder;//todo; check that exists
    ob_start();
}
function do_throw($message) {
    throw new Exception($message);
}
function get_param_one_of($index,$values){
    $ans=fr_param($index);
    if (array_search($ans,$values))
            return $ans;
    return $values[0];
}
function fr_param($key,$default=null) {
    return get_index($_REQUEST,$key,$default);
}

function get_index($array,$key,$default=null){
    if (!isset($array[$key]))
        return $default;
    return $array[$key];
}
function get_cookie($index, $default="") {
    if (!isset($_COOKIE[$index]))
        return $default;
    $ans = trim($_COOKIE[$index]);
    if ($ans == "")
        return $default;
    return $ans;
}
function mysqli_fetch_all_alt($res) {
    $table = array();
    while (true) {
        $row = mysqli_fetch_assoc($res);
        if (!$row)
            break;
        array_push($table, $row);
    }
    return $table;
}
function fr_replace_template($template){
    global $template_stack;
    end($template_stack)->template=$template;
}
function fr_placeholder_set_bulk($array){
    foreach ($array as $key => $value) {
        fr_placeholder_set($key, $value);        
    }
}

?>
