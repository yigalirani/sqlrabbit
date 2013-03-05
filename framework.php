<?php
$last_error = null;
$template_stack=array();
$the_default_action=null;
include "../mc2/classTextile.php"; # adjust paths if needed.
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
        $ans=$this->look_ahead();
        $this->head++;
        return $ans;
    }
    function look_ahead(){
        if ($this->head >= count($this->tokens))
            return null;
        return $this->tokens[$this->head];
    }
}
function parse_href(&$tokens){
    $ans=array();
    while(true){
        $name=$tokens->read_token();    
        if ($name==']')
            return $ans;
        if ($tokens->read_token()!='='){
            do_throw('syntax error in template');
        }
        $value=$tokens->read_token();    
        $ans[$name]=$value;
        if ($tokens->look_ahead()=='&'){
            $tokens->read_token();
        }
    }
}
function fr_td($content,$class=null){
    if ($class)
        print("<td class=$class>$content</td>");
    else
        print("<td>$content</td>");
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
        if ($token=='['){
            array_push($ans->elements,parse_href($tokens));
            continue;
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
function clear_empty_strings($tokens_paded){
    $tokens=array();
    foreach($tokens_paded as $token) //overcoming a quirq in preg_match_all
        if ($token!="" && $token!=null)
            array_push ($tokens, $token); 
    return $tokens;
}
function parse_template($template){
    /*split
     */
    $content=file_get_contents($template);
    $content=str_replace('./', '#script_path/', $content);
    preg_match_all('/[^\{\}#\[\]\=\&]*|#\w*|\{|\}|\[|\]|\&|\=/', $content, $matches, PREG_PATTERN_ORDER);
    $tokens=clear_empty_strings($matches[0]);
    return parse_block(new Tokens($tokens),false);
}
function is_placeaholder($element){
    $prefix=substr($element, 0,1);
    return is_string($element) && $prefix=='#';
}
function process_href($frame,$href,$is_optional){
    $ans=array();
    foreach($href as $key=>$value){
        if (is_placeaholder($value)){
            $value=get_index($frame->placeholders,  substr($value,1));
            //if (!$value && $is_optional){
            //    return "";
            //}
           // $value=urlencode($value);
        }
        $ans[$key]=$value;
    }
    return 'href='.back_routing($ans);        
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
        if (is_string($element)){
            array_push($ans,$element);
            continue;
        }
        if (is_array($element)){
            $href_result=process_href($frame,$element,$block->is_optional);
            if (!$href_result)//by thoeram, the block is optional
                return "";  
            array_push($ans,$href_result);
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
    $script_path=dirname($_SERVER['SCRIPT_NAME']);
    fr_placeholder_set('script_path',$script_path);
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
    $sqlierror="";
   if ($conn)
     	$sqlierror=mysqli_error($conn);
    return fr_get_last_error() . $sqlierror;
}
function fr_int_param($name,$default=0){
    return intval(fr_param($name,$default));    
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

function fr_run($default_action){
    global $the_default_action;
    $the_default_action=$default_action;
    $orig_request_count=count($_REQUEST);
    if (!isset($_REQUEST['action']))
        $_REQUEST['action']=$default_action;
    set_error_handler("capture_error", E_WARNING); 

    do_the_routing();
    $action='on_'.fr_param('action','default');
    try{
        fr_push_template('template.htm');
        if (function_exists('on_init'))
            on_init();//make it so that is optional
        if ($orig_request_count)
            fr_placeholder_set ('meta','<meta name="robots" content="noindex" />');
        $action();
    } catch (Exception $e) {
        print ("<pre>$e<?pre>");
    }
    while(fr_pop_template());
}
function fr_toggle($value,$options){
    return $value==$options[0]?$options[1]:$options[0];
}
function fr_compare($a,$b,$sort,$dir){
    $dir=($dir=="desc"?-1:1);
    $a_val=$a[$sort];
    $b_val=$b[$sort];
    if ($a_val==$b_val)
        return 0;
    if ($a_val>$b_val)
        return $dir;
    return -1*$dir;
}
function fr_link($content,$values,$copy=array()){
    $href=fr_href($values,$copy);
    return "<a href=$href>$content</a>";    
}
function fr_href($replace,$copy_array=array()) {
    $request=array() ;
    foreach ($copy_array as $key)
        if (isset($_REQUEST[$key]))
            $request[$key]=$_REQUEST[$key];
    foreach ($replace as $key => $value) 
        $request[$key]=$value;
    return back_routing($request);
}
function fr_placeholder_append($name,$value){
    global $template_stack;
    end($template_stack)->placeholders[$name].=$value;//todo: check that the placeholder exists?
}
function fr_placeholder_set($name,$value){
    global $template_stack;
    end($template_stack)->placeholders[$name]=$value;//todo: check that the placeholder exists?
}
function fr_redirect_and_exit($action=null,$params=null) {
    global $the_default_action;
    if (!$action){
        $action=$the_default_action;
    }
    $url=  fr_href(array('action'=>$action),$params);
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
function fr_get_param_one_of($index,$values){
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
function get_index_unset(&$array,$key,$default=null){
    if (!isset($array[$key]))
        return $default;
    $ans=$array[$key];
    unset($array[$key]);
    return $ans;
}
function fr_get_cookie($index, $default="") {
    if (!isset($_COOKIE[$index]))
        return $default;
    $ans = trim($_COOKIE[$index]);
    if ($ans == "")
        return $default;
    return $ans;
}
function fr_mysqli_fetch_all_alt($res) {
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
$routes=array(); 
function fr_route_pre($action, $mandatory_params_string="",$optional_param=null){//action is detairmened by prefix. action and prefix are the same
    global $routes;
    $mandatory_params=explode('/',$mandatory_params_string);
    $mandatory_params=clear_empty_strings($mandatory_params);
    $routes[$action]=array('mandatory'=>$mandatory_params,'optional'=>$optional_param);
}
function fr_route_filetype($action,$mandatory_params_string,$filetype){
}
function build_query_if_needed($request){
    if (count($request)==0)
        return "";
    return '?'.http_build_query($request);
}
function back_routing(&$request){
    global $the_default_action;
    global $script_path;    
    global $routes;
    $action=$request['action'];
    $action_details=get_index($routes,$action);
    if (!$action_details){
        return $script_path.'/index.php'.build_query_if_needed($request);
    }
    $ans=array();
    unset($request['action']);
    array_push($ans,$action);
    foreach($action_details['mandatory'] as $param)
        array_push($ans,get_index_unset($request,$param));
    $optional=$action_details['optional'];
    if ($optional)
        array_push($ans,get_index_unset($request,$optional));
    $ans=clear_empty_strings($ans);
    if (count($ans)==1 && $ans[0]=$the_default_action && count($request)==0)
        return $script_path;
    $path=join('/',$ans);        
    $ans=$script_path."/".$path;
    if (count($request))
        $ans.=build_query_if_needed($request);
    return $ans;
}

function do_the_routing(){
    global $script_path;
    global $routes;
    $script_path=dirname($_SERVER['SCRIPT_NAME']);
    if ($script_path=='\\')
        $script_path="";//work around bug
    $uri=$_SERVER['REQUEST_URI'];
    $pat='#^'.$script_path.'([^\?]*)#';
    preg_match_all($pat, $uri, $matches, PREG_PATTERN_ORDER);
    $path=$matches[1][0];
    $path_array=clear_empty_strings(explode("/", $path));
    var_dump($path);
    var_dump($path_array);   
    if (count($path_array)==0)
        return;
    $action_details=get_index($routes,$path_array[0]);
    if (!$action_details)
        return;
    $mandatory_params=$action_details['mandatory'];
    if (count($mandatory_params)>count($path_array)+1)
        return;
    $_REQUEST['action']=$path_array[0];
    $i=1;
    foreach($mandatory_params as $param){
        $_REQUEST[$param]=$path_array[$i++];
    }
    $optional_param=$action_details['optional'];
    if (!optional_param||count($path_array)<count($mandatory_params)+2)
        return;
    $_REQUEST[$optional_param]=$path_array[$i];
  }
function fr_query_one_request($table,$field){
    return fr_query_one($table,$field,fr_param($field));
}
function fr_query_all($table,$id_col=null){
    global $conn;
    $res = mysql_query("select * from $table", $conn);
    $ans = array();
    if (!$res)
        return $ans;
    while ($row = mysql_fetch_array($res, MYSQL_ASSOC)){
        if ($id_col)
            $ans[$row[$id_col]] = $row;
        else 
            array_push($ans,$row);
    }
    return $ans;    
}
function fr_query($q,$error_message=null) {
    $res = mysql_query($q);
    fr_check_sql_error($res,$error_message);
    if (!$res)
        return false;
    return mysql_fetch_array($res, MYSQL_ASSOC);
}
function fr_query_one($table,$field,$value){  
    $value = mysql_real_escape_string($value);
    $field=$table.'_'.$field;
    $query = "select * from mc_$table where $field='$value'";
    $ans = fr_query($query);
    return $ans;    
}
function fr_placeholder_print_from_row($row,$spec,$table_prefix=null){
    if ($table_prefix)
        $table_prefix=$table_prefix.'_';
    $spec_lines=explode(',',$spec);
    foreach($spec_lines as $name){
        fr_placeholder_set($name,get_index($row,$table_prefix.$name));
    }
}
function fr_textile($content){
    global $textile;
    if (!isset($textile))
        $textile=new Textile();
    return $textile->TextileThis($content);
 }
function fr_check_sql_error($res,$error_message){
    global $conn;
    if (!$res && $error_message){
       fr_placeholder_append('tech_error', $error_message.':'.fr_get_last_error_sqli($conn));
       fr_exit();         
    }
}
function fr_connect($server,$database,$user,$password,$conn_num=1){
    global $conn;
    $conn=mysql_pconnect($server, $user, $password);
    fr_check_sql_error($conn,"connent");
    $ans = mysql_select_db($database,$conn);
    fr_check_sql_error($ans,'select db');
}
function fr_auto_session($cookie_name,$salt){
    //turns on the auto session feature using the given params
}
function fr_update($table_name, $insert, $where) {
    $ans = "";
    ;
    foreach ($insert as $name => $value)
        $ans.=",$name='" . mysql_escape_string($value) . "'";
    $ans = "update $table_name set " . substr($ans, 1) . " where $where";
    mc_execute($ans);
}
?>
