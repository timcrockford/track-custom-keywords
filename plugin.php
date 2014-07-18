<?php

    /*
     Plugin Name: Track Custom Keywords
     Plugin URI: https://github.com/timcrockford/track-custom-keywords
     Description: Adds a new field to track if a keyword is random or custom.
     Version: 0.1
     Author: Tim Crockford
     Author URI: http://codearoundcorners.com/
     */
    
    // These are the YOURLS actions and filters that this plugin extends.
    yourls_add_action( 'activated_track-custom-keywords/plugin.php', 'tck_activate' );
    yourls_add_action( 'deactivated_track-custom-keywords/plugin.php', 'tck_deactivate' );
    yourls_add_action( 'post_add_new_link', 'tck_insert_link' );
    yourls_add_filter( 'random_keyword', 'tck_add_random' );
    yourls_add_filter( 'custom_keyword', 'tck_add_custom' );
    yourls_add_filter( 'keyword_is_free', 'tck_keyword_is_free' );
    yourls_add_filter( 'add_new_link', 'tck_add_new_link' );
    yourls_add_filter( 'table_add_row_cell_array', 'tck_table_add_row_cell_array' );
    yourls_add_filter( 'get_shorturl_charset', 'tck_get_shorturl_charset' );
    yourls_add_filter( 'rnd_string', 'tck_rnd_string' );
    yourls_add_filter( 'shunt_edit_link', 'tck_yourls_edit_link' );
    yourls_add_filter( 'table_head_cells', 'tck_table_head_cells' );
    yourls_add_filter( 'html_tfooter', 'tck_add_search_options' );
    yourls_add_action( 'html_footer', 'tck_jquery_on_load' );
    yourls_add_filter( 'table_edit_row', 'tck_table_edit_row' );
    yourls_add_action( 'yourls_ajax_edit_save_custom', 'tck_yourls_ajax_edit_save_custom' );
    yourls_add_action( 'html_head', 'tck_custom_js' );
    yourls_add_filter( 'admin_list_where', 'tck_admin_list_where' );
    
    // This is where we define the prefix characters we will use to indicate a random
    // or custom keyword. It's important these characters are not part of your usual
    // YOURLS character set.
    define("TCK_DEFAULT_RANDOM_PREFIX", "^");
    define("TCK_DEFAULT_CUSTOM_PREFIX", "%");
    define("TCK_COLUMN", "tck_custom");
    
    // This function amends the database when the plugin is activated.
    function tck_activate($args) {
        global $ydb;
        $table = YOURLS_DB_TABLE_URL;
        
        $sql = 'Alter Table `' . $table . '` Add Column `' . TCK_COLUMN . '` TINYINT(1) Default 0 After clicks';
        $ydb->query($sql);
    }
    
    // This function amends the database to remove the added column when deactivated.
    // Be warned this will wipe your custom flags.
    function tck_deactivate($args) {
        global $ydb;
        $table = YOURLS_DB_TABLE_URL;
        
        $sql = 'Alter Table `' . $table . '` Drop Column `' . TCK_COLUMN . '`;';
        $ydb->query($sql);
    }
    
    // These functions get the current prefixs stored in the database, or grab the default
    // one if there isn't one registered.
    function tck_get_prefix($mode) {
        if ( $mode == 0 ) {
            $option = 'tck_random_prefix';
        } else if ( $mode == 1 ) {
            $option = 'tck_custom_prefix';
        }

        if ( yourls_get_option($option) !== false ) {
            $prefix = yourls_get_option($option);
        } else {
            if ( $mode == 0 ) {
                $prefix = TCK_DEFAULT_RANDOM_PREFIX;
            } else if ( $mode == 1 ) {
                $prefix = TCK_DEFAULT_CUSTOM_PREFIX;
            }
            
            yourls_add_option($option, $prefix);
        }
        
        return $prefix;
    }
    
    // This function overrides the standard insert link routine to add the column for
    // custom keyword checking, and will update the inserted keyword with the appropriate
    // data.
    function tck_insert_link($args) {
        global $ydb;
        $keyword = $args[1];
        $table = YOURLS_DB_TABLE_URL;
        
        // Next we update the keyword based on the prefix.
        $prefix = substr($keyword, 0, 1);
        $fkeyword = substr($keyword, 1, strlen($keyword) - 1);

        $custom = 0;
        if ( $prefix == tck_get_prefix(0) ) $custom = 0;
        if ( $prefix == tck_get_prefix(1) ) $custom = 1;
        
        $sql = 'Update ' . $table . ' Set `keyword` = \'' . $fkeyword . '\', ';
        $sql .= '`' . TCK_COLUMN . '` = ' . $custom . ' Where `keyword` = \'' . $keyword . '\';';

        $ydb->query($sql);
    }
    
    // This function appends an "r" to the keyword if it was randomly generated. This
    // will be stripped out later.
    function tck_add_random( $keyword, $url, $title ) {
        return tck_get_prefix(0) . $keyword;
    }
    
    // This function appends a "c" to the keyword if it was manually specified. This
    // will be stripped out later.
    function tck_add_custom( $keyword, $url, $title ) {
        return tck_get_prefix(1) . $keyword;
    }

    // This is a modified version of the keyword_is_free function that takes into
    // account the prefix being added to the keyword. We functionally ignore the
    // original check as it was based on the keyword with prefix added.
    function tck_keyword_is_free( $free, $keyword ) {
        $fkeyword = tck_get_keyword($keyword);
        $free = true;
        
        if ( yourls_keyword_is_reserved($fkeyword) || yourls_keyword_is_taken($fkeyword) )
            $free = false;
        
        return $free;
    }
    
    // We override the add_new_link function to return the keyword with the prefix
    // stripped out, but only if adding the URL was a success.
    function tck_add_new_link( $return, $url, $keyword, $title ) {
        if ( $return['status'] == 'success' ) {
            $fkeyword = tck_get_keyword($keyword);
            $return['url']['keyword'] = $fkeyword;
            $return['shorturl'] = yourls_site_url(false) . '/' . $fkeyword;
        }
        
        return $return;
    }
    
    // This function checks the data for a new row on the admin screen, and if the keyword
    // contains a prefix character, it will adjust the output of the row to ensure the
    // correct data is displayed.
    function tck_table_add_row_cell_array( $cells, $keyword, $url, $title, $ip, $clicks, $timestamp ) {
        $fkeyword = tck_get_keyword($keyword);
        
        if ( $fkeyword != $keyword ) {
            $shorturl = yourls_link($fkeyword);
            $cells['keyword']['shorturl'] = yourls_esc_url($shorturl);
            $cells['keyword']['keyword_html'] = yourls_esc_html( $fkeyword );
            $cells['actions']['keyword'] = $fkeyword;
        }
        
        $newcells['custom']['template'] = '%custom%';
        $newcells['custom']['custom'] = (tck_is_custom_keyword($fkeyword) ? 'Yes' : 'No');
        $newcells['actions'] = $cells['actions'];

        unset($cells['actions']);
        return array_merge($cells, $newcells);
    }
    
    // This function adds the prefixs as valid characters so they're not stripped out of
    // the various functions that pass them around.
    function tck_get_shorturl_charset($charset) {
        return $charset . tck_get_prefix(0) . tck_get_prefix(1);
    }
    
    // This function returns the sanitized keyword from the input. If a prefix character
    // is discovered, it strips it off, otherwise it returns the keyword as is.
    function tck_get_keyword($keyword) {
        if ( substr($keyword, 0, 1) == tck_get_prefix(0) || substr($keyword, 0, 1) == tck_get_prefix(1) ) {
            $keyword = substr($keyword, 1, strlen($keyword) - 1);
        }
        
        return $keyword;
    }
    
    // This function overrides the random string generator. By default it will include
    // our prefix characters if $type = 0. We need to regenerate the string if that's
    // the case. It will only regenerate the string if one of the prefix characters has
    // been identified, otherwise it lets it pass as is.
    function tck_rnd_string( $str, $length, $type, $charlist ) {
        if ( $type == 0 ) {
            if ( strpos($str, tck_get_prefix(0)) !== false || strpos($str, tck_get_prefix(1)) !== false ) {
                $charlist = str_replace(tck_get_prefix(0), '', $charlist);
                $charlist = str_replace(tck_get_prefix(1), '', $charlist);
                $str = substr(str_shuffle($charlist), 0, $length);
            }
        }
        
        return $str;
    }
    
    // This function adds an additional check to the edit link function to update the
    // custom flag to 1 if the old and new keywords are different.
    function tck_edit_link($return, $url, $keyword, $newkeyword, $title, $new_url_already_there, $keyword_is_ok) {
        $prefix = substr($newkeyword, 0, 1);
        $newkeyword = substr($newkeyword, 1, strlen($newkeyword) - 1);
        
        if ( $prefix == tck_get_prefix(0) ) $custom = 0;
        if ( $prefix == tck_get_prefix(1) ) $custom = 1;
        
        global $ydb;
        $table = YOURLS_DB_TABLE_URL;
        $sql = '';
        
        if ( $return['status'] == 'success' && $keyword != $newkeyword && ! $custom ) {
            $sql = 'Update ' . $table . ' Set `' . TCK_COLUMN . '` = 1 Where `keyword` = \'' . $newkeyword . '\';';
        } else {
            $sql = 'Update ' . $table . ' Set `' . TCK_COLUMN . '` = ' . ($custom ? 1 : 0) . ' Where `keyword` = \'' . $newkeyword . '\';';
        }

        if ( $sql != '' ) $ydb->query($sql);
        
        $return['message'] = $sql;

        return $return;
    }
    
    // This function adds the column for the custom indicator to the table headings.
    function tck_table_head_cells($cells) {
        $newcells['custom'] = yourls__('Custom Keyword');
        $newcells['actions'] = $cells['actions'];
        
        unset($cells['actions']);
        return array_merge($cells, $newcells);
    }
    
    // This function returns true if the specified keyword is a custom keyword.
    function tck_is_custom_keyword($keyword) {
        global $ydb;
        $table = YOURLS_DB_TABLE_URL;

        $is_custom = $ydb->get_row("SELECT " . TCK_COLUMN . " As custom FROM `$table` WHERE `keyword` = '" . $keyword . "';");
        return ($is_custom->custom == 1);
    }
    
    // This function registers some jQuery to override some of the non-hooked
    // functionality.
    function tck_jquery_on_load() {
    ?>
    <script language="javascript">
        $(document).ready(function(){
            $("#main_table").find("tfoot").find("tr").find("th").attr('colspan', 7);
            $("#main_table_head_custom").removeClass("sorter-false");
            $("#main_table_head_custom")[0].sortDisabled = false;
            $("#main_table_head_actions").addClass("sorter-false")[0].sortDisabled = true;
            $("#main_table").trigger("update");
        });
    </script>
    <?php
    }
        
    // This function increases the column span to allow for the extra column.
    function tck_table_edit_row($return, $keyword, $url, $title) {
        $id = yourls_string2htmlid( $keyword );
        
        $return = str_replace('colspan="6"', 'colspan="7"', $return);
        $return = str_replace('colspan="5"', 'colspan="6"', $return);
        
        $checked = '';
        if ( tck_is_custom_keyword($keyword) ) $checked = ' checked';
        
        $return = str_replace('</td><td', '<br /><strong>Custom Keyword</strong>: <input type="checkbox" id="edit-custom-' . $id . '" name="edit-custom-' . $id . '"' . $checked . ' /></td><td', $return);
        $return = str_replace('edit_link_save', 'edit_link_save_custom', $return);
        
        return $return;
    }
        
    function tck_yourls_ajax_edit_save_custom($args) {
		yourls_verify_nonce( 'edit-save_'.$_REQUEST['id'], $_REQUEST['nonce'], false, 'omg error' );
        
		$return = tck_yourls_edit_link( null, null, $_REQUEST['url'], $_REQUEST['keyword'], $_REQUEST['newkeyword'], $_REQUEST['title'], $_REQUEST['custom'] );
		echo json_encode($return);
    }
    
    /*
    The original yourls_edit_link function passes the keyword twice into the function. We only
    need it once, so we label the first occurance $keywordid and subsequently ignore it in the
    function.
    */
    function tck_yourls_edit_link( $retval, $keywordid, $url, $keyword, $newkeyword='', $title='', $custom = 0 ) {
        global $ydb;

        $table = YOURLS_DB_TABLE_URL;
        $url = yourls_escape (yourls_sanitize_url( $url ) );
        $keyword = yourls_escape( yourls_sanitize_string( $keyword ) );
        $title = yourls_escape( yourls_sanitize_title( $title ) );
        $newkeyword = yourls_escape( yourls_sanitize_string( $newkeyword ) );
        $strip_url = stripslashes( $url );
        $strip_title = stripslashes( $title );
        $old_url = $ydb->get_var( "SELECT `url`, `" . TCK_COLUMN . "` As custom FROM `$table` WHERE `keyword` = '$keyword';" );

        // Check if new URL is not here already
        if ( $old_url != $url && !yourls_allow_duplicate_longurls() ) {
            $new_url_already_there = intval($ydb->get_var("SELECT COUNT(keyword) FROM `$table` WHERE `url` = '$url';"));
        } else {
            $new_url_already_there = false;
        }

        // Check if the new keyword is not here already
        if ( $newkeyword != $keyword ) {
            $keyword_is_ok = yourls_keyword_is_free( $newkeyword );
        } else {
            $keyword_is_ok = true;
        }

        // Check if we're changing the custom flag.
        $new_custom = false;
        if ( $custom != $old_url->custom ) {
            $new_custom = true;
        }
        
        yourls_do_action( 'pre_edit_link', $url, $keyword, $newkeyword, $new_url_already_there, $keyword_is_ok );

        // All clear, update
        if ( ( !$new_url_already_there || yourls_allow_duplicate_longurls() || $new_custom ) && $keyword_is_ok ) {
            $sql = "UPDATE `$table` SET `url` = '$url', `keyword` = '$newkeyword', `title` = '$title', `" . TCK_COLUMN . "` = " . $custom . " WHERE `keyword` = '$keyword';";
            $update_url = $ydb->query($sql);
            
            if( $update_url ) {
                $return['url']     = array( 'keyword' => $newkeyword, 'shorturl' => YOURLS_SITE.'/'.$newkeyword, 'url' => $strip_url, 'display_url' => yourls_trim_long_string( $strip_url ), 'title' => $strip_title, 'display_title' => yourls_trim_long_string( $strip_title ), 'custom' => ($custom == 1 ? 'Yes' : 'No') );
                $return['status']  = 'success';
                $return['message'] = yourls__( 'Link updated in database' );
            } else {
                $return['status']  = 'fail';
                $return['message'] = /* //translators: "Error updating http://someurl/ (Shorturl: http://sho.rt/blah)" */ yourls_s( 'Error updating %s (Short URL: %s) - SQL: %s', yourls_trim_long_string( $strip_url ), $keyword, $sql ) ;
            }

        // Nope
        } else {
            $return['status']  = 'fail';
            $return['message'] = yourls__( 'URL or keyword already exists in database' );
        }

        return yourls_apply_filter( 'edit_link', $return, $url, $keyword, $newkeyword, $title, $new_url_already_there, $keyword_is_ok );
    }

    function tck_custom_js() {
?>
    <script language="javascript">
    function edit_link_save_custom(id) {
        add_loading("#edit-close-" + id);
        var newurl = encodeURI( $("#edit-url-" + id).val() );
        var newkeyword = $("#edit-keyword-" + id).val();
        var title = $("#edit-title-" + id).val();
        var custom = ($("#edit-custom-" + id).is(':checked') ? 1 : 0);
        var keyword = $('#old_keyword_'+id).val();
        var nonce = $('#nonce_'+id).val();
        var www = $('#yourls-site').val();

        $.getJSON(
            ajaxurl,
                {action:'edit_save_custom', url: newurl, id: id, keyword: keyword, newkeyword: newkeyword, title: title, custom: custom, nonce: nonce },
            function(data){
                if(data.status == 'success') {

                    if( data.url.title != '' ) {
                        var display_link = '<a href="' + data.url.url + '" title="' + data.url.url + '">' + data.url.display_title + '</a><br/><small><a href="' + data.url.url + '">' + data.url.display_url + '</a></small>';
                    } else {
                        var display_link = '<a href="' + data.url.url + '" title="' + data.url.url + '">' + data.url.display_url + '</a>';
                    }

                    $("#url-" + id).html(display_link);
                    $("#keyword-" + id).html('<a href="' + data.url.shorturl + '" title="' + data.url.shorturl + '">' + data.url.keyword + '</a>');
                    $("#timestamp-" + id).html(data.url.date);
                    $("#edit-" + id).fadeOut(200, function(){
                        $('#main_table tbody').trigger("update");
                    });
                    $('#keyword-'+id).val( newkeyword );
                    $('#custom-'+id).html( data.url.custom );
                    $('#statlink-'+id).attr( 'href', data.url.shorturl+'+' );
                }
                feedback(data.message, data.status);
                end_loading("#edit-close-" + id);
                end_disable("#actions-" + id + ' .button');
            }
        );
    }
    </script>
<?php
    }
    
    function tck_add_search_options() {
        $options = array();
        $options[0] = 'Random & Custom Keywords';
        $options[1] = 'Random Keywords';
        $options[2] = 'Custom Keywords';
        
        $default = 0;
        if ( isset($_GET['custom_filter']) ) $default = $_GET['custom_filter'];
        
        $html = yourls_html_select('custom_filter', $options, $default, false);
        $html = str_replace("\n", '', $html);
        $html = str_replace("\"", '\\"', $html);
?>
    <script language="javascript">
        $(document).ready(function(){
            tck_filter = $("#filter_options").find("br")[0];
            $( tck_filter ).after("Show only <?php echo $html; ?><br />");
        });
    </script>
<?php
    }
    
    function tck_admin_list_where($where) {
        if ( isset($_GET['custom_filter']) ) {
            $mode = $_GET['custom_filter'];
            
            if ( $mode == 1 ) $where .= ' AND `' . TCK_COLUMN . '` = 0';
            if ( $mode == 2 ) $where .= ' AND `' . TCK_COLUMN . '` = 1';
        }
        
        return $where;
    }
?>
