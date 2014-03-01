<?php
$last_error = null;
$template_stack=array();
$the_default_action=null;

class TemplateFrame{
    public $template; //must be a block
    public $current_placeholder;
    public $last_placeholder;
    public $placeholders=array();
    public $errors=array();
    public function __construct($template) {
        $this->template=$template ;
        $this->current_placeholder='body';
    }
    public function set_current_placeholder($placeholder){
        $this->last_placeholder=$this->current_placeholder;
        $this->current_placeholder=$placeholder;
    }
    public function restore_placeholder(){
        $this->current_placeholder=$this->last_placeholder;
    }
};

class Block{
    //public $required_placeholders; //aray of required variables. empty for  non otional
    public $is_optional=false;
    public $elements; //array of items. each can be either block or string. string can prefixed with # or not
    public $alt_loc=-1;
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
class Plugin{
    public $action;
    public $params=array();
};
function parse_plugin($action,&$tokens){    
    $ans=new Plugin();
    $ans->action=substr($action,1,strlen($action)-2);
    while(true){
        $name=$tokens->read_token();    
        if ($name==']')
            return $ans;
        if ($tokens->read_token()!='='){
            do_throw('syntax error in template');
        }
        $value=$tokens->read_token();    
        $ans->params[$name]=$value;
        if ($tokens->look_ahead()==','){
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
function parse_named_block(&$tokens){
    $ans=new Block();
    $ans->is_optional=false;
    while(true){
        $token=$tokens->read_token();    
        if (!$token){
            do_throw('mismatching [[');
            return $ans;
        }        
        if (substr($token,0,1)=='['){
            array_push($ans->elements,parse_plugin($token,$tokens));
            continue;
        }
        if ($token==']]'){
            return $ans;
        }        
        if ($token=='{'){
            array_push($ans->elements,parse_block($tokens,true));
            continue;
        }
        array_push($ans->elements,$token);                
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
        if ($is_optional&&$token=="::" && $ans->alt_loc==-1){
            $ans->alt_loc=count($ans->elements);
            continue;
        }
        if (substr($token,0,2)=='[['){
            $name=substr($token,2,strlen($token)-1);
            $block=parse_named_block($tokens);
            set_template($GLOBALS['template_filename'].'#'.$name,$block);
            continue;
        }        
        if (substr($token,0,1)=='['){
            array_push($ans->elements,parse_plugin($token,$tokens));
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
function all_except_star($chars){
    return '[^'.preg_quote($chars).']*';
}
function p($a){
    return "$a";
}
function make_or_regex($or,$chars){    
    $singles=array_map('preg_quote',str_split($chars));
    $ar=array_merge($or,$singles);
    return '/'.join ('|',$ar).'/';
}
function trim_content($content){
}
function init_template_cache(){
    global $template_cache;    
    global $template_cache_is_dirty;
    if (isset($template_cache))
        return;
    $template_cache_is_dirty=false;
    $contents=file_get_contents ("template.tmp");
    if (false&&$contents){ //just create every time for dev mod
        $template_cache=unserialize ($contents);
        if (gettype($template_cache)=='array')
            return;
    }
    $template_cache=array();
    $template_cache_is_dirty=true;
}

function get_template($template){
    global $template_cache;
    $ans=get_index($template_cache,$template);
    if ($ans)
        return $ans;
    parse_template($template);
    $ans=get_index($template_cache,$template);
    if ($ans){
        $template_cache_is_dirty=true;
    }
    return $ans;
}
function parse_template($template){     
    global $template_filename;
    $template_filename=explode('#',$template);
    $template_filename=$template_filename[0];
    $content=file_get_contents($template_filename);
    trim_content($content);
    $content=str_replace('./', '#script_path/', $content);
    $unmarked=all_except_star(':{}#[]=,');
    $reg_ex=make_or_regex(
            array(
                $unmarked,
                '#\w*',
                '\[\[\w+',
                '\:\:',                
                '\]\]',                
                '\[\w+:'),
            '{}[]=:,');     
    preg_match_all($reg_ex,$content, $matches, PREG_PATTERN_ORDER);
    $tokens=clear_empty_strings($matches[0]);
    set_template($template_filename,parse_block(new Tokens($tokens),false));
}
function set_template($template_name,$block){
    $GLOBALS['$emplate_cache_is_dirty']=true;
    $GLOBALS['template_cache'][$template_name]=$block;
}
function is_placeaholder($element){
    $prefix=substr($element, 0,1);
    return is_string($element) && $prefix=='#' && strlen($element)>1;
}
function process_plugin($frame,$href,$is_validate){
    $ans=array();
    foreach($href->params as $key=>$value){
        if (is_placeaholder($value)){
            $value=get_index($frame->placeholders,  substr($value,1));           
        }
        $ans[$key]=$value;
    }
    $method='plugin_'.$href->action;
    return $method($ans,$is_validate);
    //return 'href='.back_routing($ans);        
}
function process_block_alt($frame,$block){
    if ($block->alt_loc==-1)
         return "";
    $ans=array();
    for ($i=$block->alt_loc;$i<count($block->elements);$i++){
        $element=$block->elements[$i];
        if (is_string($element)){
            if (is_placeaholder($element)){
                $value=get_index($frame->placeholders,  substr($element,1));
                if ($value)
                    array_push($ans,$value);
                continue;                
            }            
            array_push($ans,$element);
            continue;
        }
        if (is_object($element)&&get_class($element)=='Plugin'){
            $plugin_result=process_plugin($frame,$element,false);
          //  if (!$plugin_result)//by thoeram, the block is optional
            //    return "";  
            array_push($ans,$plugin_result);
            continue;
        }
        //if reached here than it must be a block todo assert that?
        $block_result=process_block($frame,$element);
        array_push($ans,$block_result);        
    }
    return join("",$ans);
}

function process_block($frame,$block){
    $ans=array();
    $count=count($block->elements);
    if ($block->is_optional && $block->alt_loc!=-1){
            $count=$block->alt_loc;
    }
    for ($i=0;$i<$count;$i++){
        $element=$block->elements[$i];
        if (is_string($element)){
            if (is_placeaholder($element)){
                $value=get_index($frame->placeholders,  substr($element,1));
                if (!$value && $block->is_optional){
                    return process_block_alt($frame,$block);
                }
                array_push($ans,$value);
                continue;                
            }            
            array_push($ans,$element);
            continue;
        }
        if (is_object($element)&&get_class($element)=='Plugin'){
            $plugin_result=process_plugin($frame,$element,false);
          //  if (!$plugin_result)//by thoeram, the block is optional
            //    return "";  
            array_push($ans,$plugin_result);
            continue;
        }
        //if reached here than it must be a block todo assert that?
        $block_result=process_block($frame,$element);
        array_push($ans,$block_result);        
    }
    return join("",$ans);
}
function fr_set_error($name,$value){
    get_top()->errors[$name]=$value;
}
function fr_get_error($name){
    return get_index(get_top()->errors,$name);
}
function fr_pop_template(){
    global $template_stack;
    if (count($template_stack)==0)
        return false;
    private_flush_current_placeholder();
    $script_path=dirname($_SERVER['SCRIPT_NAME']);
    fr_placeholder_set('script_path',$script_path);
    $frame=get_top();
    
    $top_block=get_template($frame->template);
    $str=process_block($frame,$top_block); //gets printed to the current placeholder of the above stack or to the screen
    array_pop($template_stack);
    echo ob_get_clean();
    print($str);
    return true;
    
    /*read the template from file, and produce an array of one of three types
     * text
     * placeholder
     * optional block - again, an array of the same
     * non optinal block
     * how to recognize the types? for now: text is a string, placeholder is a string that starts with '#', and an optional block is an array
     * next: write a function that compilers a templates (split_template)
     */
}
function fr_validate(){
    $frame=get_top();
    $top_block=get_template($frame->template);
    return validate_block($frame,$top_block); //get           
}
function fr_push_template($template,$validate=false){
    ob_start();
    global $template_stack;
    array_push($template_stack,new TemplateFrame($template));
    fr_placeholder_printo("body"); // todo: parse the tempale at this stage and extraxt the default placeholder
    if ($validate)
        return fr_validate();
    return 0;
}
function validate_block($frame,$block){
    $ans=0;//returns num errors
    foreach($block->elements as $element){
        if (is_object($element)&&get_class($element)=='Plugin'){
            $ans+=process_plugin($frame,$element,true);
        }
    }
    return $ans;
}

function fr_exit(){
    throw new Exception('exit');
}

function fr_int_param($name,$default=0,$min=null,$max=null){
    $ans=intval(fr_param($name,$default));    
    if ($min!=null)
        $ans=max($min,$ans);
    if ($max!=null)
        $ans=min($max,$ans);
    return $ans;        
}
function fr_mandatory_param($name){
    $ans=fr_param($name,null);
    if ($ans==null)
        fr_redirect_and_exit("");
    return $ans;
}
function fr_override_values(&$a,$b){
    if (!$b)
        return;
    foreach($b as $key=>$value)
        $a[$key]=$value;//get_index($b,$key,$value);
}
function fr_print_template_at($placeholder,$file_name,$values=array(),$values2=null){
    fr_placeholder_printo($placeholder);
    fr_override_values($values,$values2);
    fr_push_template($file_name);
    foreach($values as $key=>$value){
        fr_placeholder_set ($key, $value);
    }
    fr_pop_template();  
    private_flush_current_placeholder();
    
    get_top()->restore_placeholder(); 
}
function fr_print_template($file_name,$values=array(),$values2=null){
    fr_override_values($values,$values2);
    fr_push_template($file_name);
    foreach($values as $key=>$value){
        fr_placeholder_set ($key, $value);
    }
    fr_pop_template();    
}
function plugin_a($request,$is_validate){
    $text=get_index($request,'text');
    $action=get_index($request,'action');
    if (!$action)
        $action=title_to_name ($text);
    if (!$text)
        $text=$action;
    else
        unset($request['text']);
    $request['action']=$action;
    return fr_link($text,$request);
}
function plugin_href($request,$is_validate){
    return back_routing($request);
}
function title_to_name($title){
    return strtolower(str_replace (' ', '_',$title));
}
function plugin_trtext($params,$is_validate){
    $name=get_index($params,'name');
    $title=get_index($params,'title');
    $type=get_index($params,'type','text');
    if (!$name)
        $name=title_to_name($title);
    if (!$title)
        $title=$name;
    $mandatory_msg=get_index($params,'mandatory_msg');
    if ($is_validate){
        if ($mandatory_msg && !fr_param($name)){
            fr_set_error($name,$mandatory_msg);
            return 1;
        }
        return 0;
    }
    ob_start();
    echo "<tr><td>";
    if ($mandatory_msg)
        echo "<span class=form_error>*</span> ";
    echo "$title</td><td>";
    $value=fr_param($name);
    $error=fr_get_error($name);
    if ($error)
        echo "<div class=form_error>$error</div>";
    echo "<input type=$type name=$name value=$value></td></tr>";
    return ob_get_clean();
    //[trtext:title=Email Address,name=email,mandatory_msg=please enter your email]
}
function capture_error($errno, $err_string) {
    global $last_error;
    $last_error = $err_string;
    append_to_tech_error($err_string);
    
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
    init_template_cache();
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
    save_template_cache();

}

function save_template_cache(){
    if ($GLOBALS['template_cache_is_dirty']){
        file_put_contents ('template.tmp',serialize($GLOBALS['template_cache']));
    }
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
    if ($copy_array){
        foreach ($copy_array as $key)
            if (isset($_REQUEST[$key]))
                $request[$key]=$_REQUEST[$key];
    }
    foreach ($replace as $key => $value) 
        $request[$key]=$value;
    return back_routing($request);
}
function append_hash(&$hash,$name,$value){
    if (isset($hash[$name]))
        $hash[$name].=$value;
    else
        $hash[$name]=$value;
}
function fr_placeholder_append($name,$value){
    global $template_stack;
    append_hash(end($template_stack)->placeholders,$name,$value);//todo: check that the placeholder exists?
}
function fr_placeholder_set($name,$value){
    global $template_stack;
    end($template_stack)->placeholders[$name]=$value;//todo: check that the placeholder exists?
}
function fr_redirect_and_exit($action=null,$params=array()) {
    global $the_default_action;
    if (!$action){
        $action=$the_default_action;
    }
    $params['action']=$action;
    $url=  fr_href($params);
    header("Location: $url", true, 303);
    save_template_cache();
    die();
}
function f_clear_print(){
    ob_get_clean();
    ob_start();
}
function private_flush_current_placeholder(){
    global $template_stack;
    $value=ob_get_clean();
    $current_placeholder=end($template_stack)->current_placeholder;
    fr_placeholder_append($current_placeholder,$value);
    ob_start();
}
function append_to_tech_error($msg){
    global $template_stack;
    append_hash($template_stack[0]->placeholders,'tech_error',$msg.'<br>');
}
function &get_top(){
    global $template_stack;
    return $template_stack[count($template_stack)-1];
}
function fr_placeholder_printo($placeholder){    
    private_flush_current_placeholder();
    get_top()->set_current_placeholder($placeholder);
}
function do_throw($message) {
    append_to_tech_error($message);
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
    if (!isset($array[$key])){
        return $default;
    }
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

function fr_replace_template($template){
    global $template_stack;
    end($template_stack)->template=$template;
}
function fr_placeholder_set_bulk($array){
    if (!$array)
        return;
    foreach ($array as $key => $value) {
        fr_placeholder_set($key, $value);        
    }
}
function explode_clear($string){
    $ans=explode('/',$string);
    return clear_empty_strings($ans);
}
$routes=array(); 
$file_type_routes=array();
$reverse_file_type_routes=array();
function fr_route_pre($action, $mandatory_params_string="",$optional_param=null){//action is detairmened by prefix. action and prefix are the same
    global $routes;
    $routes[$action]=array('mandatory'=>explode_clear($mandatory_params_string),'optional'=>$optional_param);
}
function fr_route_filetype($action,$optional_param,$mandatory_params_string,$filetype){
    global $file_type_routes;
    global $reverse_file_type_routes;
    $details=array('mandatory'=>explode_clear($mandatory_params_string),'optional'=>$optional_param,'file_type'=>$filetype,'action'=>$action);
    $file_type_routes[$action]=$details;
    $reverse_file_type_routes[$filetype]=$details;
}
function build_query_if_needed($request){
    if (count($request)==0)
        return "";
    return '?'.http_build_query($request);
}
function back_routing_file_type($action,$request,$action_details){
    global $the_default_action;
    global $script_path;    
    global $routes;
    global $file_type_routes;
    $ans=array();
    //array_push($ans,$action);
    $optional=$action_details['optional'];
    if ($optional)
        array_push($ans,get_index_unset($request,$optional));
    foreach($action_details['mandatory'] as $param)
        array_push($ans,get_index_unset($request,$param));
    
    $ans=clear_empty_strings($ans);
    if (count($ans)==0 && $ans[0]=$the_default_action && count($request)==0)
        return $script_path;
    $path=join('/',$ans).'.'.$action_details['file_type'];
    $ans=$script_path."/".$path;
    if (count($request))
        $ans.=build_query_if_needed($request);
    return $ans;    
}
function back_routing(&$request){
    global $the_default_action;
    global $script_path;    
    global $routes;
    global $file_type_routes;
    $action=get_index($request,'action',$_REQUEST['action']);
    $action_details=get_index($file_type_routes,$action);
    if ($action_details){
        unset($request['action']);
        return back_routing_file_type($action,$request,$action_details);
    }    
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
function do_the_routing_file_type($uri, $matches){
    global $the_default_action;
    global $script_path;    
    global $routes;
    global $file_type_routes;
    global $reverse_file_type_routes;
    $path=$matches[1];
    $file_type=$matches[2];
    $path_array=clear_empty_strings(explode("/", $path));
    //var_dump($path);
    //var_dump($path_array);   
    if (count($path_array)==0)
        return;
    $action_details=get_index($reverse_file_type_routes,$file_type);
    if (!$action_details)
        return;
    $mandatory_params=$action_details['mandatory'];
    if (count($mandatory_params)>count($path_array)+1)
        return;
    $_REQUEST['action']=$action_details['action'];
    $i=count($path_array)-count($mandatory_params);
    foreach($mandatory_params as $param){
        $_REQUEST[$param]=$path_array[$i++];
    }
    $optional_param=$action_details['optional'];
    if (!$optional_param||count($path_array)<count($mandatory_params)+1){
        return;
    }
    $_REQUEST[$optional_param]=$path_array[0];    
}
function do_the_routing(){
    global $script_path;
    global $routes;
    $script_path=dirname($_SERVER['SCRIPT_NAME']);
    if ($script_path=='\\')
        $script_path="";//work around bug
    $uri=$_SERVER['REQUEST_URI'];
    $pat='#^'.$script_path.'([^\.\?]*)\.(\w+)#';
    $num_pat=preg_match_all($pat, $uri, $matches, PREG_SET_ORDER);
    if ($num_pat){
        return do_the_routing_file_type($uri,$matches[0]);
    }
    $pat='#^'.$script_path.'([^\?]*)#';
    $num_pat=preg_match_all($pat, $uri, $matches, PREG_PATTERN_ORDER);
    $path=$matches[1][0];
    $path_array=clear_empty_strings(explode("/", $path));
    //var_dump($path);
    //var_dump($path_array);   
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
    if (!$optional_param||count($path_array)<count($mandatory_params)+2)
        return;
    $_REQUEST[$optional_param]=$path_array[$i];
  }
function fr_placeholder_print_from_row($row,$spec,$table_prefix=null){
    if ($table_prefix)
        $table_prefix=$table_prefix.'_';
    $spec_lines=explode(',',$spec);
    foreach($spec_lines as $name){
        fr_placeholder_set($name,get_index($row,$table_prefix.$name));
    }
}
function fr_print_template_list($placeholder,$template,$array,$sep=null){
    fr_placeholder_printo($placeholder);
    foreach($array as $tuple){
        fr_print_template($template,$tuple,null);
        echo $sep;
    }
    private_flush_current_placeholder();
    get_top()->restore_placeholder();    
}

function fr_first_valid($tuple,$names){
    $names_array=explode(',',$names);
    foreach($names_array as $name){
        $ans=get_index($tuple,$name);
        if ($ans)
            return $ans;
    }
}

?>
