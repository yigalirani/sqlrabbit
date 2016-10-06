<?php
class SF{ 
    static $routes = array();
    static $error_list = array();
    static $script_path = null;
    static $the_default_action='';
};
function fr_get_last_error(){
    return join(",",SF::$error_list);
}
class Tokenizer{
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
class Block{
    //public $required_placeholders; //aray of required variables. empty for  non otional
    public $is_optional=false;
    public $elements; //array of items. each can be either block or string. string can prefixed with # or not
    public function __construct() {
        $this->elements=array();
    }
};
class Plugin{
    public $action;
    public $params=array();
};
function make_tokenizer($template_filename){     
    $content=file_get_contents($template_filename);
    $content=str_replace('./', '$script_path/', $content);
    $tokens=preg_split ('/(['.  preg_quote('[,:=]').'])|(\$\w+)|(\{\{)|(\}\})/',$content,-1,PREG_SPLIT_DELIM_CAPTURE|PREG_SPLIT_NO_EMPTY);
    return new Tokenizer($tokens);
}
function read_mandatory(&$tokens,$expected){
    $next=$tokens->read_token();
    if ($next!=$expected){
        throw new Exception('syntax error in template');
    }
}
function parse_plugin(&$tokens){    
    $ans=new Plugin();
    $ans->action=$tokens->read_token();
    read_mandatory($tokens,':');
    while(true){
        $name=$tokens->read_token();    
        if ($name==']'){
            return $ans;
        }
        read_mandatory($tokens,'=');
        $value=$tokens->read_token();    
        $ans->params[$name]=$value;
        
        if ($tokens->look_ahead()==','){
            $tokens->read_token();
            //else it must be ']' which will get read next iter, or mistake with will get cought next iter
        }
    }
}
function parse_block(&$tokens,$is_optional){
    $ans=new Block();
    $ans->is_optional=$is_optional;
    while(true){
        $token=$tokens->read_token();    
        if (!$token){
            if ($is_optional)
                throw new Exception('mismatching }');
            if (!count($ans->elements))
                throw new Exception('empty template');
            return $ans;
        }      
         
        if ($token=='['){
            array_push($ans->elements,parse_plugin($tokens));
            continue;
        }
        if ($token=='{{'){
            array_push($ans->elements,parse_block($tokens,true));
            continue;
        }
        if ($token=='}}' & $is_optional)
            return $ans;
        array_push($ans->elements,$token);                
    }   
}
function is_placeaholder($element){
    $prefix=substr($element, 0,1);
    return is_string($element) && $prefix=='$' && strlen($element)>1;
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
function render_plugin($plugin,$params,$is_validate){
    $ans=array();
    foreach($plugin->params as $key=>$value){
        if (is_placeaholder($value)){
            $value=get_index($params,  substr($value,1));           
        }
        if (!is_null($value)){
            $ans[$key]=$value;
        }
    }
    $method='plugin_'.$plugin->action;
    return $method($ans,$is_validate);
    //return 'href='.back_routing($ans);        
}
function render_block($block,$params){
    $ans=array();
    foreach ($block->elements as $element){
        if (is_string($element)){
            if (!is_placeaholder($element)){
                array_push($ans,$element);
                continue;
            }
            $value=get_index($params,  substr($element,1));
            if (!$value && $block->is_optional)
                return "";
            array_push($ans,$value);
            continue;
        }
        if (is_object($element)&&get_class($element)=='Plugin'){
            $plugin_result=render_plugin($element,$params,false);
          //  if (!$plugin_result)//by thoeram, the block is optional
            //    return "";  
            array_push($ans,$plugin_result);
            continue;
        }
        //if reached here than it must be a block todo assert that?
        $block_result=render_block($element,$params);
        array_push($ans,$block_result);    
    }
    return join("",$ans);
}
function get_parsed_template($template_filename){
    //todo: cache parsed templates
    $tokenizer=make_tokenizer($template_filename);
    $parsed_template=parse_block($tokenizer,false);
    return $parsed_template;
}
function fr_route_pre($action, $mandatory_params_string="",$optional_param=null){//action is detairmened by prefix. action and prefix are the same
    SF::$routes[$action]=array('mandatory'=>explode_remove_empty('/',$mandatory_params_string),'optional'=>$optional_param);
}
function fr_render($template,$params,$params2=array()){
    $params=array_replace($params,$params2);
    $parsed_template=get_parsed_template($template);
    $params['script']=SF::$script_path;
    $ans=render_block($parsed_template,$params);
    return $ans;
}
function capture_error($errno, $err_string) {
    array_push(SF::$error_list,$err_string);
}
function plugin_href($request,$is_validate){
    return fr_href($request);
}
function calc_script_path(){
    $ans=dirname($_SERVER['SCRIPT_NAME']);
    if ($ans=='\\')
        return "";
    return $ans;
}
function calc_routing_params($default_action){
    $script_path=calc_script_path();
    $uri=$_SERVER['REQUEST_URI'];
    $pat='#^'.$script_path.'([^\?]*)#';
    $num_pat=preg_match_all($pat, $uri, $matches, PREG_PATTERN_ORDER);
    $path=$matches[1][0];
    $ans=array('action'=>$default_action);
    $path_array=explode_remove_empty("/", $path); 
    if (count($path_array)==0)
        return $ans;
    $action=$path_array[0];
    if (!$action)
        return $ans;
    $ans['action']=$action;   
    $action_details=get_index(SF::$routes,$action);
    if (!$action_details){
        $ans['action']=$default_action;
        return $ans;
    }
    $mandatory_params=$action_details['mandatory'];
    if (count($mandatory_params)>count($path_array)+1)
        return $ans;

    $i=1;
    foreach($mandatory_params as $param){
        if ($i>=count($path_array)){
            break;
        }
        $ans[$param]=$path_array[$i++];
    }
    $optional_param=$action_details['optional'];
    if (!$optional_param||count($path_array)<count($mandatory_params)+1)
        return $ans;
    $ans[$optional_param]=get_index($path_array,$i);  
    return $ans;
}
function http_build_query_if_needed($request){
    if (count($request)==0)
        return "";
    return '?'.http_build_query($request);
}
function build_query_if_needed($request){
    if (count($request)==0)
        return "";
    return '?'.http_build_query($request);
}
function fr_link($content,$values,$copy_array=array()){
    $href=fr_href($values,$copy_array);
    return "<a href=$href>$content</a>";    
}
function fr_href($replace,$copy_array=array()) {
    $request=array() ;
    foreach ($copy_array as $key) //copy values from the original request
        if (isset($_REQUEST[$key]))
            $request[$key]=$_REQUEST[$key];
    $request=  array_replace($request, $replace);
    return calc_href_from_request($request);
}
function clear_empty_strings($tokens_paded){
    $tokens=array();
    foreach($tokens_paded as $token) //overcoming a quirq in preg_match_all
        if ($token!="" && $token!=null)
            array_push ($tokens, $token); 
    return $tokens;
}
function explode_remove_empty($sep,$path){
    return clear_empty_strings(explode($sep,$path));
}
function calc_href_from_request($request){
    $action=get_index($request,'action',$_REQUEST['action']);
    $action_details=get_index(SF::$routes,$action);
    if (!$action_details)
        return SF::$script_path.'/index.php'.http_build_query_if_needed($request);
    $ans=array();
    unset($request['action']);
    array_push($ans,$action);
    foreach($action_details['mandatory'] as $param)
        array_push($ans,get_index_unset($request,$param));
    $optional=$action_details['optional'];
    if ($optional)
        array_push($ans,get_index_unset($request,$optional));
    $ans=array_filter($ans);
    if (count($ans)==1 && $ans[0]=SF::$the_default_action && count($request)==0)
        return SF::$script_path;
    $path=join('/',$ans);        
    $ans=SF::$script_path."/".$path;
    if (count($request))
        $ans.=build_query_if_needed($request);
    return $ans;
}
function fr_toggle($value,$options){
    return $value==$options[0]?$options[1]:$options[0];
}
function fr_get_param_one_of($index,$values){
    $ans=fr_param($index);
    if (array_search($ans,$values))
            return $ans;
    return $values[0];
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
function fr_run($default_action){
    //$_REQUEST=array_map("htmlspecialchars", $_REQUEST);
    SF::$script_path=calc_script_path();
    SF::$the_default_action=$default_action;
    set_error_handler("capture_error", E_WARNING|E_NOTICE); 
    $routing_params=calc_routing_params($default_action);
    $_REQUEST=array_replace($routing_params,$_REQUEST);//chied is 'action;
    $user_func='on_'.$_REQUEST['action'];
    print call_user_func($user_func);
}
function fr_run_old($func_name){
    print call_user_func($func_name);
}
function fr_mandatory_param($name){
    $ans=fr_param($name,null);
    if ($ans==null)
        fr_redirect_and_exit("");
    return $ans;
}
function fr_param($key,$default=null) {
    return get_index($_REQUEST,$key,$default);
}
function fr_redirect_and_exit($action=null,$params=array()) {
    if (!$action){
        $action=SF::$the_default_action;
    }
    $params['action']=$action;
    $url=  fr_href($params);
    header("Location: $url", true, 303);
    die();
}
function fr_int_param($name,$default=0,$min=null,$max=null){
    $ans=intval(fr_param($name,$default));    
    if ($min!=null)
        $ans=max($min,$ans);
    if ($max!=null)
        $ans=min($max,$ans);
    return $ans;        
}
function fr_read_cookie($name,$default_value){ //todo: decrypt the cookie like all the cook framework
    $val=unserialize(get_index($_COOKIE,$name));
    if (!is_array($val)){
        return $default_value;
    }
    $ans= array_replace($default_value,$val);
    return $ans;
}
