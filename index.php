<?php
/* sql rabbit. Easily browse sql databases
 * copyright: yigal irani, yigal@symbolclick.com, licence: GPL
 */
require_once ("framework.php");
require_once ("settings.php");
class M{ //Module vars -- all variables that are used in this file are stored here for consise access. 
    static $max_rows = 100;
    static $conn = null;
    static $nav_copy_fields = array('sort', 'database', 'query', 'table', 'action', 'dir');
    static $settings;
    function __construct (){
        SqlRabbitVars::$settings=get_settings();
    }
};
function write_cookies() {
    setcookie("settings", serialize(M::$settings));
}
function read_cookies() {
    M::$settings=fr_read_cookie('settings',get_settings());
}
function get_last_error_sqli($conn){
    $sqlierror="";
   if ($conn)
     	$sqlierror=mysqli_error($conn);
    return fr_get_last_error() . $sqlierror;
}
function do_connect() {
    M::$conn = mysqli_connect(M::$settings['server'], M::$settings['user'], M::$settings['password']);
    return get_last_error_sqli(M::$conn);
}
function do_connect_ex() {
    read_cookies();
    $error = do_connect();
    if ($error != ""){
        fr_redirect_and_exit('login');
    }
    $ans=array_replace($_REQUEST,M::$settings);
    if (M::$settings['password'] == "")
        $ans['logout_comment']='logging without password';
    return $ans;
}    

function on_login() {  
    read_cookies();
    return  fr_render("login_page.htm",M::$settings);
}
function on_login_submit() {
    read_cookies();
    foreach(M::$settings as $key=>$value){
        M::$settings[$key]=get_index($_REQUEST,$key,$value);
    }
    write_cookies();
    $error = do_connect();
    if (!$error){
        fr_redirect_and_exit();
    }
    $vp=M::$settings;
    $vp['error']=$error;
    return fr_render('login_page.htm', $vp);
}
function on_logout() {
    setcookie("settings", "");
    fr_redirect_and_exit("login");
}
function do_query($query,&$vp) {
    $database = fr_param('database');
    if ($database != "") {
        $ans = mysqli_select_db(M::$conn, $database);
        if (!$ans) {
            $vp['query_error']='cannot connect to database '.$database. ' ' . get_last_error_sqli(M::$conn);
            return NULL;
        }
    }
    $res = mysqli_query(M::$conn, $query);
    if (!$res) {
        $vp['query_error']= "mysql_query failed " . get_last_error_sqli(M::$conn);
        return NULL;
    }
    return $res;
}
function print_val_td($val) {
    if ($val === null)
        $val = "<span class=ns>null</span>";
    else if ($val === true || $val === false)
        $val = "<span class=ns>$val</span>";
    return("<td>$val</td>");
}
function print_last_line($num_fields, $no_rows_at_all) {
    $ans='';
    $ans.=print_title("*");
    $ans.="<td colspan=$num_fields><b>";
    if ($no_rows_at_all)
        $ans.="(There are no rows in this table)";
    else
        $ans.="(There are no more rows)";
    $ans.="</b></td>\n";
    return $ans;
}
function print_table($query) {
    $ans=[];
    $start = fr_int_param('start', '0');
    $sort = fr_param("sort");
    $dir = fr_param("dir");
    $query_decoration='';
    if ($sort)
        $query_decoration.=" order by $sort $dir ";
    $query_decoration.=" limit $start,".M::$max_rows;
    $ans['query_decoration']=$query_decoration;
    $res = do_query($query . $query_decoration,$ans); //guratied to return value or throw
    //fr_placeholder_printo('table'); //all printouts will be directed to the body
    $buf='';
    $num_fields = mysqli_num_fields($res);
    $buf.=("\n<table id=data><tr>");
    $buf.=print_title("   ");
    for ($i = 0; $i < $num_fields; $i++)
        $buf.=print_sort_title(mysqli_fetch_field_direct($res, $i)->name);
    $buf.=("</tr>");
    $dont_print_next = false;
    for ($c = 0; $c < M::$max_rows; $c++) {
        $buf.=("<tr>\n");
        $row = mysqli_fetch_row($res);
        if (!$row) {
            $buf.=print_last_line($num_fields, $c == 0 and $start == 0);
            $dont_print_next = true;
            break;
        }
        $buf.=print_title($c + $start + 1);
        foreach ($row as $key => $val) {
            $buf.=print_val_td($val);
        }
        $buf.=("</tr>");
    }
    $buf.=("</table>\n");
    $ans['nextprev']=print_next_prev($dont_print_next, $start);
    $ans['table']=$buf;
    return $ans;
}
function on_table() {
    $vp=do_connect_ex();
    $table = fr_mandatory_param('table');
    $database = fr_mandatory_param('database');
    $vp['about']="The table below shows the table $table, you can select either schema or data view";
    $vp['view_options']=print_switch("class=selected", "");
    $vp['title']= "$database / $table";
    $query = "select * from $table";
    $vp['navbar']=databases_link() . " / " . database_link() . " / " . $table;
    $vp['query']= $query;
    $print_result=print_table($query);
    return fr_render('template.htm', $vp,$print_result);    
}
function on_table_schema() {
    $vp=do_connect_ex();
    $table = fr_mandatory_param('table');
    $database = fr_mandatory_param('database');
    $vp['about']= "The table below shows the table $table, you can select either schema or data view";
    $vp['view_options']=print_switch("", "class=selected");
    $query = "describe $table";
    $vp['query']= $query;
    $vp['navbar']= databases_link() . " / " . database_link() . " / " . $table;
    $print_result=print_cached_nav_result($query, null, null);
    return fr_render('template.htm', $vp,$print_result);
}
function on_query() {
    $vp=do_connect_ex();
    $vp['about']= 'Enter any sql query';
    $vp['title']= 'User query';
    $query = fr_param("query");
    $vp['querytext']= $query;
    $vp['navbar']= databases_link();
    $database = fr_param('database');
    if ($database) {
        $vp['navbar'].= " / " . database_link();
        $vp['about'].=" for database $database";
    }
    $vp['navbar'].= " / query";
    if (preg_match("/^select/i", $query))
        $print_result=print_table($query);
    else
        $print_result=print_cached_nav_result($query, null, null);
    return fr_render('template.htm', $vp,$print_result);  
}
function on_databases() {
    $vp=do_connect_ex();
    $vp['about']='The table below shows all the databases that are accessible in this server: Click on any database below to browse it';
    $vp['title']='show databases';
    $query = 'show databases';
    $vp['query']= $query;
    $print_result=print_cached_nav_result($query, null, "decorate_database_name");
    return fr_render('template.htm', $vp,$print_result);
}
function decorate_table_name($val) {
    return fr_link($val,array('action' => 'table', 'table' => $val), array('database'));
}
function on_database() {
    $vp=do_connect_ex();
    $database = fr_param('database');
    $vp['about']="The table below shows all the available tables in the database $database, Click on any table below to browse it";
    $vp['title']="show database $database";
    $query = "show table status;";
    $vp['query']= $query;
    $vp['navbar']= databases_link() . " / " . $database;
    $print_result=print_cached_nav_result($query, array(0, 1, 4, 17), 'decorate_table_name');
    return fr_render('template.htm', $vp,$print_result);    
}
function decorate_database_name($val) {
    return fr_link($val, array('action' => 'database', 'database' => $val));
}
function print_switch($table_class, $schema_class) {
    $data_ref = fr_href(array('action'=>'table'), array('database', 'table'));
    $schema_href = fr_href(array('action'=>'table_schema'), array('database', 'table'));
    return "(  <a $table_class href=$data_ref>Data</a> | <a $schema_class href=$schema_href>Schema</a> )";
}
function databases_link() {
    return fr_link('databases',array('action' => 'databases'));
}
function database_link() {
    return decorate_database_name(fr_param('database'));
}
function print_sort_title($s) {
    $sort = fr_param("sort");
    if ($sort == $s) {
        $dir_values = array("asc", "desc");
        $dir = fr_get_param_one_of("dir", $dir_values);
        $other_dir = fr_toggle($dir, $dir_values);
        $href = fr_href(array('dir'=>$other_dir), M::$nav_copy_fields);
        $img = "<img src=".SF::$script_path."/media/$dir.png>";
        return("<td class=heading id=$s><a href=$href>$s  $img</a></td>\n");
    } else {
        $link = fr_link($s, array('sort' => $s, 'dir' => 'asc'), M::$nav_copy_fields);
        return("<td class=heading id=$s>$link</td>\n");
    }
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
function print_cached_nav_result($query, $shown_columns, $first_column_decorator) {
    $ans=[];
    $res = do_query($query,$ans);
    if (!$res)
        return $ans;
    if ($res === true) {
        $ans['ok']='query completed succesfuly'; //an exec query
        return $ans;
    }
    $sort = fr_param("sort");
    $dir = fr_param("dir");
    $table = mysqli_fetch_all_alt($res); //,MYSQLI_ASSOC);
    if ($sort) {
        $f = function($a, $b) use ($sort, $dir) {
                    return fr_compare($a, $b, $sort, $dir);
                };
        usort($table, $f);
    }
    $num_fields = mysqli_num_fields($res);
    $buf='';
    $buf.="\n<table id=data><tr>";
    $buf.=print_title("   ");
    if ($shown_columns) {
        $shown_columns_hash = array();
        foreach ($shown_columns as $key) {
            $shown_columns_hash[$key] = 1;
        }
    }
    for ($i = 0; $i < $num_fields; $i++) {
        if ($shown_columns && !isset($shown_columns_hash[$i]))
            continue;
        $buf.=print_sort_title(mysqli_fetch_field_direct($res, $i)->name);
    }
    $buf.="</tr>";
    $start = fr_int_param("start");
    $array_count = count($table);
    $dont_print_next = false;
    for ($i = $start; $i < $start + M::$max_rows; $i++) {
        if ($i >= $array_count)
            $row = null;
        else
            $row = $table[$i];
        $buf.="<tr>\n";
        if (!$row) {
            $buf.=print_last_line($num_fields, $i == 0);
            $dont_print_next = true;
            break;
        }
        $buf.=print_title($i + 1);
        $key = -1;
        foreach ($row as $name => $val) {
            $key++;
            if ($shown_columns)
                if (!isset($shown_columns_hash[$key]))
                    continue;
            if ($key == 0 && $first_column_decorator)
                $val = call_user_func($first_column_decorator, $val);
            $buf.=print_val_td($val);
        }
        $buf.=("</tr>");
    }
    $buf.=("</table>\n");
    $ans['table']=$buf;
    $ans['nextprev']=print_next_prev($dont_print_next, $start);
    return $ans;
}
function print_next_prev($dont_print_next, $start) {
    $buf='';
    if ($start >= M::$max_rows) {
        $buf.=fr_link('Last', array('start' => $start - M::$max_rows), M::$nav_copy_fields);
    }else
        $buf.="Last";
    $buf.= "&nbsp;&nbsp;&nbsp; |&nbsp;&nbsp;&nbsp";
    if (!$dont_print_next) {
        $buf.=(fr_link('Next', array('start' => $start + M::$max_rows), M::$nav_copy_fields));
    }else
        $buf.= "Next";
    return $buf;
}
function print_title($s) {
    return "<td class=heading id=$s>$s</td>\n";
}
fr_route_pre('database', 'database','start');//action is detairmened by prefix. action and prefix are the same
fr_route_pre('table', 'database/table','start');
fr_route_pre('table_schema', 'database/table','start');
fr_route_pre('databases', '','start');
fr_run('databases');
?>