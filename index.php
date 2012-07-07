<?php
/* sql rabbit. Easily browse sql databases
 * copyright: yigal irani, yigal@symbolclick.com, licence: GPL
 */
require_once ("settings.php");
class Connection {
    private $script;
    private $server;
    private $user;
    private $password;
    private $error;    
    public $conn;
    function __construct() {
        set_error_handler("capture_error", E_WARNING);
        $this->logo="<a href=index.php><img src=logo.png></a>";
        $action = get_param("action");
        switch ($action) {
            case "print_login":
                $this->print_login_dialog();
            case "login":
                $this->server = get_param("server");
                $this->user = get_param("user");
                $this->password = get_param("password");
                $this->do_connect(true);
                $this->write_cookies();
                redirect_and_exit();
            case "logout":
                setcookie("password", "no_password");
                setcookie("user", "");
                setcookie("server", "");
                redirect_and_exit("index.php?action=print_login");
            default:
                $this->read_cookies();
                $this->do_connect(false);
        }
        restore_error_handler();
    }
    private function write_cookies() {
        setcookie("server", $this->server);
        setcookie("user", $this->user);
        setcookie("password", $this->password);
        if (!$this->password)
            setcookie("password", "no_password");
            
                
    }
    private function read_cookies() {
        $settings=new SQLRabbitSettings;
        $this->server = get_cookie("server", $settings->default_server);
        $this->user = get_cookie("user",  $settings->default_user);
        $this->password = get_cookie("password",$settings->default_password);
        if ($this->password=="no_password")
                $this->password="";
    }
    private function do_connect($show_error) {
        get_last_error();
        $this->conn = mysqli_connect($this->server, $this->user, $this->password);
        $error = get_last_error() . mysqli_error($this->conn);
        if ($error != "")
            $this->print_login_dialog($show_error?$error:"");
    }
    public function print_current() {
        $status = "ok";
        if ($this->error)
            $status = "<div class=error>$this->error</div>";
        $logout_link = "<a href=index.php?action=logout>logout</a>";
        if ($this->password == "")
            $logout_link = " (logging without password) " . $logout_link;
        print <<<END
        <div class=connection>
            $this->logo<b>server:</b> $this->server    <b>user</b>: $this->user ($logout_link)
        </div><br>
END;
    }
    private function print_login_dialog($error="") {
         $settings=new SQLRabbitSettings;
        if ($error)
            $error="<div class=error>$error</div><br>";
        print<<<END
                $this->logo<br>
<div class=login_form>
<form action=index.php method="get" type="post">
<b>Login:</b><br>
<table>
	<tr><td>server</td><td><input name="server" type="text" value="$this->server"></td></tr>
	<tr><td>user:</td><td><input name="user" type="text" value="$this->user"></td></tr>
	<tr><td>password:</td><td><input name="password" type="password" value="$this->password"></td></tr>
</table>
	$error
	<input  type="submit" value="login" name="action"><br>
</form>
</div>
$settings->login_help;
END;
        do_throw("login dialog");
    }
}
class Content {
    private $script = "";
    private $conn;
    private $database;
    private $table;
    public $title="Easily Browse sql databases";
    private $view;
    private $navbar;
    private $query_error = "";
    private $max_rows=100;
    private $query_decoration="";
    private $about="";
    private $options="";
    private $nav_copy_fields=array('sort','database','query','table','action','dir');
    private function decorate_table_name($val){
        $href=make_href2(array('action'=>'table','table'=>$val),array('database'));
        return ("<a href=$href>$val</a>");
    }
     private function decorate_database_name($val){
         return make_link($val,array('action'=>'database','database'=>$val));
    }
    private function print_switch($table_class,$schema_class){
        $href = "index.php?database=$this->database&table=$this->table&action";
        return "(  <a $table_class href=$href=table>Data</a> | <a $schema_class href=$href=table_schema>Schema</a> )";
    }
    function __construct($conn) {
        $this->conn = $conn;
        $this->action = get_param("action");
        $this->database = get_param("database");
        $this->table = get_param("table");
        switch ($this->action) {
            case "database":
                $this->about = "The table below shows all the available tables in the database $this->database, Click on any table below to browse it";                
                $this->title = "show database $this->database";
                $this->query = "show table status;";
                $this->navbar = $this->databases_link() . " / " . $this->database;
                $database=$this->database;
                $this->body=$this->print_cached_nav_result(array(0,1,4,17), array($this,"decorate_table_name"));
                break;
            case "table":
                $this->about="The table below shows the table $this->table, you can select either schema or data view";
                 $href_schema=$this->get_schema_switch_href('table_schema');
                $this->options=$this->print_switch("class=selected",""); 
                $this->title = "$this->database / $this->table";
                $this->query = "select * from $this->table";
               
                $this->navbar = $this->databases_link() . " / " . $this->database_link() . " / " . $this->table;
                $this->body=$this->print_table();
                break;
            case "table_schema":
                $this->about="The table below shows the table $this->table, you can select either schema or data view";
                 $href=$this->get_schema_switch_href('table');
                $this->options=$this->print_switch("","class=selected"); 
                $href=$this->get_schema_switch_href('table');
                $this->query = "describe $this->table";
                $this->navbar = $this->databases_link() . " / " . $this->database_link() . " / " . $this->table;
                $this->body=$this->print_cached_nav_result(null, null);
                break;
            case "query";
                $this->about="Enter any sql query";
                $this->title = "User query";
                $this->query = get_param("query");
                $this->navbar = $this->databases_link();
                if ($this->database){
                        $this->navbar .= " / " .$this->database_link();
                        $this->about.=" for database $this->database";
                 }
                $this->navbar .=  " / query";
                if (preg_match("/^select/i", $this->query))
                        $this->body=$this->print_table();
                else
                        $this->body=$this->print_cached_nav_result(null,null);
                break; //todo:
                
            case "databases":
            default:
                $this->about = "The table below shows all the databases that are accessible in this server: Click on any database below to browse it";
                $this->query = "show databases";
                //$this->navbar = "Databases";//$this->databases_link();
                $this->action = "databases";
                $this->body=$this->print_cached_nav_result(null,array($this,"decorate_database_name"));
        }
        $query=$this->print_query_dialog();
        print("<div class=navbar>$this->navbar</div><br>$query");
        print("<br>");
        if ($this->query_error)
            print("<div class=error>$this->query_error</<div>");
        print($this->body);
    }
    private function print_query_dialog(){
        $print_about_line=function ($title,$content,$style=""){
            if ($content)
                print("<tr><td class=about_title>$title</td><td $style>$content</td><tr>");
        };        
        ob_start();
        $error_div="";
        $decorated_line="";
         $encoded_query=urlencode($this->query);
         if ($this->database)
                 $encoded_query.="&database=$this->database";
        print("<div class=about><table>");
        $print_about_line("About this view",$this->about);
        $print_about_line("View options",$this->options);
        if ($this->action=="query")
            $print_about_line("Query",$this->print_query_dialog2());
        else    
            $print_about_line("Query","<span class=sql>$this->query</span> (<a href=index.php?action=query&query=$encoded_query>Edit</a>)");
        $print_about_line("Query Decoration",$this->query_decoration,"class=limit");
        print("</table></div>");
        return ob_get_clean();
    }    
    private function print_query_dialog2(){
        $query=get_param("query");
        ob_start();
        print<<< END
<div class=query>
<form action=index.php method="get" type="post">
<textarea name="query" cols="60" rows="5">$query</textarea><br>
<input name="action" type="submit" value="query"/>
<input name="database" type="hidden" value="$this->database"/>
</form>
</div>
END;
        return ob_get_clean();
    }
    private function databases_link() {
        return "<a href=index.php?action=databases>Databases</a>";
    }
    private function database_link() {
        return "<a href=index.php?action=database&database=$this->database>$this->database</a>";
    }
    private function get_schema_switch_href($action) {
        return $href = "index.php?database=$this->database&table=$this->table&action=$action";
   }
    private function do_query($query){
        if ($this->database != "") {
            $ans = mysqli_select_db($this->conn, $this->database);
            if (!$ans) {
                $this->query_error = "cannot connect to database $this->database" . get_last_error();
                return false;
            }
        }
        $res = mysqli_query($this->conn, $query);
        if (!$res) {
            $this->query_error = "mysql_query failed " . get_last_error().mysqli_error($this->conn);
            return false;
        }
        return $res;
    }
    private function print_sort_title($s) {
        $sort=get_param("sort");
        if ($sort==$s){
            $dir_values=array("asc","desc");
            $dir=get_param_one_of("dir",$dir_values);
            $other_dir=toggle($dir,$dir_values);
            $href=make_href("dir",$other_dir,$this->nav_copy_fields);
            $img="<img src=$dir.png>";
            print("<td class=heading id=$s><a href=$href>$s  $img</a></td>\n");
        }else{
            $link=make_link($s,array('sort'=>$s,'dir'=>'asc'),$this->nav_copy_fields);
            print("<td class=heading id=$s>$link</td>\n");
        }
    }
    private function print_cached_nav_result($shown_columns,$first_column_decorator){
        $res=$this->do_query($this->query);
        if (!$res)
            return "";
        if ($res===true)
            return "<div class=ok>query completed succesfuly</div>"; //an exec query
        $sort=get_param("sort");
        $dir=get_param("dir");
        ob_start();
        $table=mysqli_fetch_all_alt($res);//,MYSQLI_ASSOC);
        
        if ($sort){
                $f=function($a,$b) use ($sort,$dir){return compare($a,$b,$sort,$dir);};
                usort($table, $f);
        }
        $num_fields = mysqli_num_fields($res);
        print("\n<table id=data><tr>");    
        print_title("   ");
        if ($shown_columns){
            $shown_columns_hash= array();
             foreach ($shown_columns as $key) {
                 $shown_columns_hash[$key]=1;
             }
         }
        for ($i = 0; $i < $num_fields; $i++) {
            if ($shown_columns && !isset($shown_columns_hash[$i]))
                continue;
            $this->print_sort_title(mysqli_fetch_field_direct($res, $i)->name);
        }
        print("</tr>");
        $start=intval(get_param("start"));
        $array_count=count($table);
        $dont_print_next=false;
        for ($i=$start;$i<$start+$this->max_rows;$i++){
            if ($i>=$array_count)
                $row=null;
            else
                $row=$table[$i];
            print("<tr>\n");
            if (!$row) {
                print_title("*");
                print("<td colspan=$num_fields><b>\n");
                if ($i == 0)
                    print("(There are no rows in this table)");
                else
                    print("(There are no more rows)");
                $dont_print_next = true;
                print("</b></td>\n");
                break;
            }
            print_title($i+1);
            $key=-1;
            foreach ($row as $name => $val) {
                $key++;
                if ($shown_columns) 
                    if (!isset($shown_columns_hash[$key]))
                        continue;
                if ($key == 0 && $first_column_decorator)
                    $val = call_user_func($first_column_decorator,$val);
                $this->print_val_td($val);
            }
            print("</tr>");
        }
        print("</table>\n");
        $the_table_printout=ob_get_clean();
        return $this->print_table_decor($the_table_printout,$dont_print_next,$start);
    }
    private function get_next_prev($dont_print_next,$start){
        ob_start();
        
        if ($start >= $this->max_rows) {
            print(make_link('Last',array('start'=>$start - $this->max_rows),$this->nav_copy_fields));
        }else
            print "Last";
        print "&nbsp;&nbsp;&nbsp; |&nbsp;&nbsp;&nbsp";
        if (!$dont_print_next) {
            print(make_link('Next',array('start'=> $start + $this->max_rows),$this->nav_copy_fields));
        }else
            print "Next";
        return ob_get_clean();
    }
    private function print_val_td($val){
        if ($val===null)
            $val="<span class=ns>null</td>";
        else if ($val===true || $val===false)
            $val="<span class=ns>$val</td>";
        print("<td>$val</td>"); 
    }
    private function print_table() {
        $start=intval(get_param("start"));
        $sort=get_param("sort");
        $dir=get_param("dir");
        $this->decorated_query=$this->query;
        $this->decorated_query_html = "$this->query";
        if ($sort)
            $this->query_decoration.=" order by $sort $dir ";
        $this->query_decoration.=" limit $start,$this->max_rows";
        $res=$this->do_query($this->query.$this->query_decoration);
        if (!$res)
            return "";
        ob_start();
        $num_fields = mysqli_num_fields($res);
        print("\n<table id=data><tr>");
        print_title("   ");
        for ($i = 0; $i < $num_fields; $i++)
            $this->print_sort_title(mysqli_fetch_field_direct($res, $i)->name);
        print("</tr>");
        $dont_print_next=false;
        for ($c = 0; $c < $this->max_rows; $c++) {
            print("<tr>\n");
            $row = mysqli_fetch_row($res);
            if (!$row) {
                print_title("*");
                print("<td colspan=$num_fields><b>");
                if ($c == 0 and $start == 0)
                    print("(There are no rows in this table)");
                else
                    print("(There are no more rows)");
                $dont_print_next = true;
                print("</b></td>\n");
                break;
            }
            print_title($c + $start + 1);
            foreach ($row as $key => $val) {
                $this->print_val_td($val);
            }
            print("</tr>");
        }
        print("</table>\n");
        $the_table_printout = ob_get_clean();
        return $this->print_table_decor($the_table_printout,$dont_print_next,$start);
    }
    private function print_table_decor($the_table_printout,$dont_print_next,$start){
        $prev_next=$this->get_next_prev($dont_print_next,$start);
        $prev_next="<div class=nextprev>$prev_next</div>";
        ob_start();
        print("<div class=query_result>$prev_next $the_table_printout $prev_next </div>");
        return ob_get_clean();
    }
}
function print_title($s) {
    print("<td class=heading id=$s>$s</td>\n");
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
function redirect_and_exit($url="index.php") {
    header("Location: $url", true, 303);
    die();
}
function do_throw($message) {
    throw new Exception($message);
}
function get_param_one_of($index,$values){
    $ans=get_param($index);
    if (array_search($ans,$values))
            return $ans;
    return $values[0];
}
function get_param($index) {
    if (!isset($_GET[$index]))
        return null;
    $ans = $_GET[$index];
    $ans = trim($ans);
    return ($ans);
}
function get_cookie($index, $default="") {
    if (!isset($_COOKIE[$index]))
        return $default;
    $ans = trim($_COOKIE[$index]);
    if ($ans == "")
        return $default;
    return $ans;
}
$last_error = null;
function capture_error($errno, $err_string) {
    global $last_error;
    $last_error = $err_string;
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
function get_last_error() {
    global $last_error;
    if (!$last_error)
        return null;
    $ans = $last_error;
    $last_error = null;
    return end(explode("):", $ans));
}
ob_start();
try {
    $conn = new Connection;
    $conn->print_current();
    $info_panel = ob_get_clean();
    ob_start();
    $content=new Content($conn->conn);
    $body = ob_get_clean();
    print_body($body, $content->title, $info_panel);
} catch (Exception $e) {
    $body = ob_get_clean();
    print_body($body, $e->getMessage(), "");
}
function print_body($body, $title, $info_panel) {
    print<<<END
<!DOCTYPE html PUBLIC "-//W3C//DTD HTML 4.01 Transitional//EN" "http://www.w3.org/TR/html4/loose.dtd">
<html><head>
 <meta http-equiv="Content-Type" content="text/html;charset=UTF-8">
 <link rel="stylesheet" href="sql.css" type="text/css" media="screen" >   
<script src="http://ajax.googleapis.com/ajax/libs/jquery/1.4.2/jquery.min.js"></script>
    <script type='text/javascript' src='script.js'></script>
<title>SQL Rabbit - $title </title></head>
<body>
    $info_panel
        $body
</body>
</html>
END;
}
