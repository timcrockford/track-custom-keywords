<?php

    /*
     Plugin Name: Track Custom Keywords
     Plugin URI: https://github.com/timcrockford/track-custom-keywords
     Description: Adds a new field to track if a keyword is random or custom.
     Version: 0.1
     Author: Tim Crockford
     Author URI: http://codearoundcorners.com/
     */
    
    yourls_add_action( 'post_add_new_link', 'tck_insert_link' );
    yourls_add_filter( 'random_keyword', 'tck_add_random' );
    yourls_add_filter( 'custom_keyword', 'tck_add_custom' );
    yourls_add_filter( 'keyword_is_free', 'tck_keyword_is_free' );
    yourls_add_filter( 'add_new_link', 'tck_add_new_link' );
    yourls_add_filter( 'table_add_row_cell_array', 'tck_table_add_row_cell_array' );
    yourls_add_filter( 'get_shorturl_charset', 'tck_get_shorturl_charset' );
    yourls_add_filter( 'rnd_string', 'tck_rnd_string' );
    yourls_add_filter( 'edit_link', 'tck_edit_link' );

    // This is where we define the prefix characters we will use to indicate a random
    // or custom keyword. It's important these characters are not part of your usual
    // YOURLS character set.
    define("TCK_DEFAULT_RANDOM_PREFIX", "@");
    define("TCK_DEFAULT_CUSTOM_PREFIX", "#");
    
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
    function tck_insert_link( $args ) {
        global $ydb;
        $keyword = $args[1];
        $table = YOURLS_DB_TABLE_URL;
        
        // We check the YOURLS option table to see if the necessary column has been added
        // to the database.
        $init = 1;
        if ( yourls_get_option('tck_column_added') === false ) $init = 0;
        
        if ( $init == 0 ) {
            $sql = 'Alter Table ' . $table . ' Add Column custom TINYINT(1) Default 0 After clicks';
            $ydb->query($sql);
            yourls_add_option('tck_column_added', 1);
        }
        
        // Next we update the keyword based on the prefix.
        $prefix = substr($keyword, 0, 1);
        $fkeyword = substr($keyword, 1, strlen($keyword) - 1);

        $custom = 0;
        if ( $prefix == tck_get_prefix(0) ) $custom = 0;
        if ( $prefix == tck_get_prefix(1) ) $custom = 1;
        
        $sql = 'Update ' . $table . ' Set `keyword` = \'' . $fkeyword . '\', ';
        $sql .= '`custom` = ' . $custom . ' Where `keyword` = \'' . $keyword . '\';';

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
            $shorturl = yourls_link( $fkeyword );
            $cells['keyword']['shorturl'] = yourls_esc_url($shorturl);
            $cells['keyword']['keyword_html'] = yourls_esc_html( $fkeyword );
            $cells['actions']['keyword'] = $fkeyword;
        }
        
        return $cells;
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
        if ( $return['status'] == 'success' && $keyword != $newkeyword ) {
            global $ydb;
            $table = YOURLS_DB_TABLE_URL;

            $sql = 'Update ' . $table . ' Set `custom` = 1 Where `keyword` = \'' . $newkeyword . '\';';
            $ydb->query($sql);
        }
        
        return $return;
    }
?>