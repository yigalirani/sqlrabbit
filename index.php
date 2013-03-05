<?php
/* sql rabbit. Easily browse sql databases
 * copyright: yigal irani, yigal@symbolclick.com, licence: GPL
 */
require_once ("framework.php");
require_once ("settings.php");
$max_rows = 100;
$conn = null;
$nav_copy_fields = array('sort', 'database', 'query', 'table', 'action', 'dir');
function write_cookies() {
    setcookie("settings", serialize($GLOBALS['settings']));
}
function read_cookies() {
    fr_override_values($GLOBALS['settings'], unserialize(fr_get_cookie('settings')));
}
function do_connect() {
    global $settings;
    global $conn;
    $conn = mysqli_connect($settings['server'], $settings['user'], $settings['password']);
    return fr_get_last_error_sqli($conn);
}
function do_connect_ex() {
    global $settings;
    read_cookies();
    fr_placeholder_set('database', fr_param('database'));
    $error = do_connect();
    if ($error != "")
        fr_redirect_and_exit('login');
    fr_placeholder_set_bulk($settings);
    if ($settings['password'] == "")
        fr_placeholder_set('logout_comment', 'logging without password');
}
function on_login() {
    fr_replace_template("login_page.htm");
    read_cookies();
    fr_placeholder_set_bulk($GLOBALS['settings']);
}
function on_login_submit() {
    read_cookies();
    fr_override_values($GLOBALS['settings'], $_REQUEST);
    write_cookies();
    $error = do_connect();
    if (!$error){
        fr_redirect_and_exit();
    }
    fr_replace_template('login_page.htm');
    fr_placeholder_set_bulk($GLOBALS['settings']);
    fr_placeholder_set('error', $error);
}
function on_logout() {
    setcookie("settings", "");
    fr_redirect_and_exit("login");
}
function do_query($query) {
    $database = fr_param('database');
    global $conn;
    if ($database != "") {
        $ans = mysqli_select_db($conn, $database);
        if (!$ans) {
            fr_placeholder_set('query_error', "cannot connect to database $database " . fr_get_last_error_sqli($conn));
            fr_exit();
        }
    }
    $res = mysqli_query($conn, $query);
    if (!$res) {
        fr_placeholder_set('query_error', "mysql_query failed " . fr_get_last_error_sqli($conn));
        fr_exit();
    }
    return $res;
}
function print_val_td($val) {
    if ($val === null)
        $val = "<span class=ns>null</span>";
    else if ($val === true || $val === false)
        $val = "<span class=ns>$val</span>";
    print("<td>$val</td>");
}
function print_last_line($num_fields, $no_rows_at_all) {
    print_title("*");
    print("<td colspan=$num_fields><b>");
    if ($no_rows_at_all)
        print("(There are no rows in this table)");
    else
        print("(There are no more rows)");
    print("</b></td>\n");
}
function print_table($query) {
    global $max_rows;
    $start = fr_int_param('start', '0');
    $sort = fr_param("sort");
    $dir = fr_param("dir");
    if ($sort)
        $query_decoration.=" order by $sort $dir ";
    $query_decoration.=" limit $start,$max_rows";
    fr_placeholder_set('query_decoration', $query_decoration);
    $res = do_query($query . $query_decoration); //guratied to return value or throw
    fr_placeholder_printo('table'); //all printouts will be directed to the body
    $num_fields = mysqli_num_fields($res);
    print("\n<table id=data><tr>");
    print_title("   ");
    for ($i = 0; $i < $num_fields; $i++)
        print_sort_title(mysqli_fetch_field_direct($res, $i)->name);
    print("</tr>");
    $dont_print_next = false;
    for ($c = 0; $c < $max_rows; $c++) {
        print("<tr>\n");
        $row = mysqli_fetch_row($res);
        if (!$row) {
            print_last_line($num_fields, $c == 0 and $start == 0);
            $dont_print_next = true;
            break;
        }
        print_title($c + $start + 1);
        foreach ($row as $key => $val) {
            print_val_td($val);
        }
        print("</tr>");
    }
    print("</table>\n");
    print_next_prev($dont_print_next, $start);
}
function on_table() {
    do_connect_ex();
    $table = fr_mandatory_param('table');
    $database = fr_mandatory_param('database');
    fr_placeholder_set('about', "The table below shows the table $table, you can select either schema or data view");
    print_switch("class=selected", "");
    fr_placeholder_set('title', "$database / $table");
    $query = "select * from $table";
    fr_placeholder_set('navbar', databases_link() . " / " . database_link() . " / " . $table);
    fr_placeholder_set('query', $query);
    print_table($query);
}
function on_table_schema() {
    do_connect_ex();
    $table = fr_mandatory_param('table');
    $database = fr_mandatory_param('database');
    fr_placeholder_set('about', "The table below shows the table $table, you can select either schema or data view");
    print_switch("", "class=selected");
    $query = "describe $table";
    fr_placeholder_set('query', $query);
    fr_placeholder_set('navbar', databases_link() . " / " . database_link() . " / " . $table);
    print_cached_nav_result($query, null, null);
}
function on_query() {
    do_connect_ex();
    fr_placeholder_set('about', 'Enter any sql query');
    fr_placeholder_set('title', 'User query');
    $query = fr_param("query");
    fr_placeholder_set('querytext', $query);
    fr_placeholder_set('navbar', databases_link());
    $database = fr_param('database');
    if ($database) {
        fr_placeholder_append('navbar', " / " . database_link());
        fr_placeholder_append('about', " for database $database");
    }
    fr_placeholder_append('navbar', " / query");
    if (preg_match("/^select/i", $query))
        print_table($query);
    else
        print_cached_nav_result($query, null, null);
}
function on_databases() {
    do_connect_ex();
    fr_placeholder_set('about', 'The table below shows all the databases that are accessible in this server: Click on any database below to browse it');
    fr_placeholder_set('title', "show databases");
    $query = 'show databases';
    fr_placeholder_set('query', $query);
    print_cached_nav_result($query, null, "decorate_database_name");
}
function decorate_table_name($val) {
    return fr_link($val,array('action' => 'table', 'table' => $val), array('database'));
}
function on_database() {
    do_connect_ex();
    $database = fr_param('database');
    fr_placeholder_set('about', "The table below shows all the available tables in the database $database, Click on any table below to browse it");
    fr_placeholder_set('title', "show database $database");
    $query = "show table status;";
    fr_placeholder_set('query', $query);
    fr_placeholder_set('navbar', databases_link() . " / " . $database);
    print_cached_nav_result($query, array(0, 1, 4, 17), 'decorate_table_name');
}
function decorate_database_name($val) {
    return fr_link($val, array('action' => 'database', 'database' => $val));
}
function print_switch($table_class, $schema_class) {
    $data_ref = fr_href(array('action'=>'table'), array('database', 'table'));
    $schema_href = fr_href(array('action'=>'table_schema'), array('database', 'table'));
    fr_placeholder_set('view_options', "(  <a $table_class href=$data_ref>Data</a> | <a $schema_class href=$schema_href>Schema</a> )");
}
function databases_link() {
    return fr_link('databases',array('action' => 'databases'));
}
function database_link() {
    return decorate_database_name(fr_param('database'));
}
function print_sort_title($s) {
    global $nav_copy_fields;
    $sort = fr_param("sort");
    if ($sort == $s) {
        $dir_values = array("asc", "desc");
        $dir = fr_get_param_one_of("dir", $dir_values);
        $other_dir = fr_toggle($dir, $dir_values);
        $href = fr_href(array('dir'=>$other_dir), $nav_copy_fields);
        global $script_path;
        $img = "<img src=$script_path/$dir.png>";
        print("<td class=heading id=$s><a href=$href>$s  $img</a></td>\n");
    } else {
        $link = fr_link($s, array('sort' => $s, 'dir' => 'asc'), $nav_copy_fields);
        print("<td class=heading id=$s>$link</td>\n");
    }
}
function print_cached_nav_result($query, $shown_columns, $first_column_decorator) {
    global $max_rows;
    $res = do_query($query);
    if (!$res)
        return;
    if ($res === true) {
        fr_placeholder_set('ok', 'query completed succesfuly'); //an exec query
        return;
    }
    $sort = fr_param("sort");
    $dir = fr_param("dir");
    $table = fr_mysqli_fetch_all_alt($res); //,MYSQLI_ASSOC);
    if ($sort) {
        $f = function($a, $b) use ($sort, $dir) {
                    return fr_compare($a, $b, $sort, $dir);
                };
        usort($table, $f);
    }
    $num_fields = mysqli_num_fields($res);
    fr_placeholder_printo('table');
    print("\n<table id=data><tr>");
    print_title("   ");
    if ($shown_columns) {
        $shown_columns_hash = array();
        foreach ($shown_columns as $key) {
            $shown_columns_hash[$key] = 1;
        }
    }
    for ($i = 0; $i < $num_fields; $i++) {
        if ($shown_columns && !isset($shown_columns_hash[$i]))
            continue;
        print_sort_title(mysqli_fetch_field_direct($res, $i)->name);
    }
    print("</tr>");
    $start = fr_int_param("start");
    $array_count = count($table);
    $dont_print_next = false;
    for ($i = $start; $i < $start + $max_rows; $i++) {
        if ($i >= $array_count)
            $row = null;
        else
            $row = $table[$i];
        print("<tr>\n");
        if (!$row) {
            print_last_line($num_fields, $i == 0);
            $dont_print_next = true;
            break;
        }
        print_title($i + 1);
        $key = -1;
        foreach ($row as $name => $val) {
            $key++;
            if ($shown_columns)
                if (!isset($shown_columns_hash[$key]))
                    continue;
            if ($key == 0 && $first_column_decorator)
                $val = call_user_func($first_column_decorator, $val);
            print_val_td($val);
        }
        print("</tr>");
    }
    print("</table>\n");
    print_next_prev($dont_print_next, $start);
}
function print_next_prev($dont_print_next, $start) {
    global $max_rows;
    global $nav_copy_fields;
    fr_placeholder_printo('nextprev');
    if ($start >= $max_rows) {
        print(fr_link('Last', array('start' => $start - $max_rows), $nav_copy_fields));
    }else
        print "Last";
    print "&nbsp;&nbsp;&nbsp; |&nbsp;&nbsp;&nbsp";
    if (!$dont_print_next) {
        print(fr_link('Next', array('start' => $start + $max_rows), $nav_copy_fields));
    }else
        print "Next";
}
function print_title($s) {
    print("<td class=heading id=$s>$s</td>\n");
}
fr_route_pre('database', 'database','start');//action is detairmened by prefix. action and prefix are the same
fr_route_pre('table', 'database/table','start');
fr_route_pre('table_schema', 'database/table','start');
fr_route_pre('databases', null,'start');
fr_run('databases');
?>