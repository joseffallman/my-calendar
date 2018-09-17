<?php

if( ! class_exists( 'WP_List_Table' ) ) {
    require_once( ABSPATH . 'wp-admin/includes/class-wp-list-table.php' );
}

class MC_event_list_table extends WP_List_Table {
    var $total_items;
    var $items_per_page;

    function __construct() {
        //global $status, $page;
                
        //Set parent defaults
        parent::__construct( array(
            'singular'  => __('Event', 'my-calendar' ),     //singular name of the listed records
            'plural'    => __('Events', 'my-calendar' ),    //plural name of the listed records
            'ajax'      => false        //does this table support ajax?
        ) );
    }

    function display() {
        $this->links_output();
        echo "<form id='search' method='get'>";
        echo "<input type='hidden' name='page' value=" . $_REQUEST['page'] . " />";
        echo "<input type='hidden' name='_wpnonce' value='" . wp_create_nonce( 'my-calendar-nonce' ) . "' />";
        $this->search_box( __( 'Search Events', 'my-calendar' ), 'mc_search' );
        echo "</form><form id='events' method='post'>";
        echo "<input type='hidden' name='page' value=" . $_REQUEST['page'] . " />";
        wp_nonce_field( 'my-calendar-nonce', 'nonce_name', true, true );
        parent::display();
        echo "</form>";
    }

    function get_columns() {
        $columns = array(
            'cb'        => '<input type="checkbox" />',
            'title' 	=> __( 'Title', 'my-calendar' ),
            'location'  => __( 'Location', 'my-calendar' ),
            'time'      => __( 'Date/Time', 'my-calendar' ),
            'author'	=> __( 'Author', 'my-calendar' ),
            'category'  => __( 'Category', 'my-calendar' )
        );
        //_e( 'ID', 'my-calendar' )
        return $columns;
    }

    function column_default( $item, $column_name ) {
        switch( $column_name ) { 
            case 'title':
            case 'location':
            case 'time':
            case 'author':
            case 'category':
                return $item[ $column_name ];
        }
    }

    function column_cb( $item ) {
        //$checked = ( 1 == $event->event_flagged ) ? 'checked="checked"' : '';
        //$checked = '';
        //$output = sprintf( '<input type="checkbox" value="%s" name="user_selected[]" id="mc%s" %s />', $item['ID'], $item['ID'], $checked );
        //$output .= sprintf( '<label for="mc%s">', $item['ID'] );
        // Translators: Event ID.
        //$output .= sprintf( __( "<span class='screen-reader-text'>Select event </span>%d", 'my-calendar' ), $item['ID'] );
        //$output .= "</label>";
        //return $output;

        return sprintf(
            '<input type="checkbox" name="user-selected[]" value="%s" />', $item['ID']
        );    
    }

    function get_sortable_columns() {
        $sortable_columns = array(
            'title'     => array( 'event_title', false ),
            'location'  => array( 'event_location', false ),
            'time'      => array( 'event_begin', false ),
            'author'    => array( 'event_author', false ),
            'category'	=> array( 'event_category', false )
        );
        return $sortable_columns;
    }

    function get_bulk_actions() {
        $actions = array(
          'delete'  => __( 'Delete events', 'my-calendar' ),
          'trash'   => __( 'Trash events', 'my-calendar' ),
        );

        if ( current_user_can( 'mc_approve_events' ) ) {
            $actions['approve'] = __( 'Publish events', 'my-calendar' );
        }

        if ( ! ( isset( $_GET['restrict'] ) && 'archived' == $_GET['restrict'] ) ) {
            $actions['archive'] = __( 'Archive events', 'my-calendar' );
        } else {
            $actions['undo_archive'] = __( 'Remove from archive', 'my-calendar' );
        }
        if ( isset( $_GET['restrict'] ) && 'flagged' == $_GET['restrict'] ) {
            $actions['not_spam'] = __( 'Not spam', 'my-calendar' );
        }

        return $actions;
    }

    function process_bulk_actions() {
        if ( false == $this->current_action() ) {
            return;
        }
        $action = $this->current_action();
        $events = $_REQUEST['user-selected'];
        $nonce = $_REQUEST['nonce_name'];
		if ( ! wp_verify_nonce( $nonce, 'my-calendar-nonce' ) ) {
            mc_show_error( __( 'Something went wrong, please try again.', 'my-calendar' ) );
            return;
        }
        if ( 0 >= count( $events ) ) {
            mc_show_error( sprintf( __( "Couln't perform %s when no events is selected", 'my-calendar' ), $action ) );
            return;
        }

        if ( 'delete' === $this->current_action() ) {
            //$this->example_data
            if ( isset( $_GET['user-selected'] ) && is_array( $_GET['user-selected'] ) ) {
                foreach( $_GET['user-selected'] as $deleteID ) {
                    $this->delete_books( $deleteID );
                }
            }
            elseif ( isset( $_GET['user-selected'] ) && is_numeric( $_GET['user-selected'] ) ) { 
                $this->delete_books( $_GET['user-selected'] );
            }
        }

        switch ( $action ) {
            case 'delete':
                $results = mc_bulk_action( 'delete', $events );
                mc_show_notice( $results );
                break;
            case 'trash':
                $results = mc_bulk_action( 'trash', $events );
                mc_show_notice( $results );
                break;
            case 'approve':
                $results = mc_bulk_action( 'approve', $events );
                mc_show_notice( $results );
                break;
            case 'archive':
                $results = mc_bulk_action( 'archive', $events );
                mc_show_notice( $results );
                break;
            case 'undo_archive':
                $results = mc_bulk_action( 'unarchive', $events );
                mc_show_notice( $results );
                break;
            case 'not_spam':
                $results = mc_bulk_action( 'unspam', $events );
                mc_show_notice( $results );
                break;
        }
    }

    function table_data() {
        global $wpdb;
        $events_table = array();
        
        if ( ! current_user_can( 'mc_approve_events' ) || 
             ! current_user_can( 'mc_manage_events'  ) || 
             ! current_user_can( 'mc_add_events'     ) ) {
            return;
        }

        $sortbydirection = ( '' == get_option( 'mc_default_direction' ) ) ? 'ASC' : get_option( 'mc_default_direction' );
        if ( isset( $_GET['order'] ) ) {
            switch ( strtoupper( $_GET['order'] ) ) {
                case 'ASC': 
                    $sortbydirection = 'ASC';
                    break;
                case 'DESC':
                    $sortbydirection = 'DESC';
                    break;
            }
        }
        
        $possible_orderby = array(	'event_ID', 
                                    'event_title', 
                                    'event_desc',
                                    'event_location',
                                    'event_author',
                                    'event_category',
                                    'event_label' );
        $sortbyvalue = ( isset( $_GET['orderby'] ) ) ? $_GET['orderby'] : get_option( 'mc_default_sort' );
        $sortbyvalue = ( in_array( $sortbyvalue, $possible_orderby ) ) ? $sortbyvalue : 'event_begin ' . $sortbydirection . ', event_time';

        $allow_filters = true;
        $status        = ( isset( $_GET['limit'] ) ) ? $_GET['limit'] : 'all';
        $restrict      = ( isset( $_GET['restrict'] ) ) ? $_GET['restrict'] : 'all';
        switch ( $status ) {
            case 'all':
                $limit = '';
                break;
            case 'draft':
                $limit = 'WHERE event_approved = 0';
                break;
            case 'published':
                $limit = 'WHERE event_approved = 1';
                break;
            case 'trashed':
                $limit = 'WHERE event_approved = 2';
                break;
            default:
                $limit = '';
        }
        switch ( $restrict ) {
            case 'all':
                $filter = '';
                break;
            case 'where':
                $filter   = ( isset( $_GET['filter'] ) ) ? $_GET['filter'] : '';
                $restrict = 'event_label';
                break;
            case 'author':
                $filter   = ( isset( $_GET['filter'] ) ) ? (int) $_GET['filter'] : '';
                $restrict = 'event_author';
                break;
            case 'category':
                $filter   = ( isset( $_GET['filter'] ) ) ? (int) $_GET['filter'] : '';
                $restrict = 'event_category';
                break;
            case 'flagged':
                $filter   = ( isset( $_GET['filter'] ) ) ? (int) $_GET['filter'] : '';
                $restrict = 'event_flagged';
                break;
            default:
                $filter = '';
        }
        if ( ! current_user_can( 'mc_manage_events' ) && ! current_user_can( 'mc_approve_events' ) ) {
            $restrict      = 'event_author';
            $filter        = get_current_user_id();
            $allow_filters = false;
        }
        $filter = esc_sql( urldecode( $filter ) );
        if ( 'event_label' == $restrict ) {
            $filter = "'$filter'";
        }
        if ( '' == $limit && '' != $filter ) {
            $limit = "WHERE $restrict = $filter";
        } elseif ( '' != $limit && '' != $filter ) {
            $limit .= "AND $restrict = $filter";
        }
        if ( '' == $filter || ! $allow_filters ) {
            $filtered = '';
        } else {
            $filtered = "<span class='dashicons dashicons-no' aria-hidden='true'></span><a href='" . admin_url( 'admin.php?page=my-calendar-manage' ) . "'>" . __( 'Clear filters', 'my-calendar' ) . '</a>';
        }
        $current        = empty( $_GET['paged'] ) ? 1 : intval( $_GET['paged'] );
        $user           = get_current_user_id();
        $screen         = get_current_screen();
        $option         = $screen->get_option( 'per_page', 'option' );
        $items_per_page = get_user_meta( $user, $option, true );
        if ( empty( $items_per_page ) || $items_per_page < 1 ) {
            $items_per_page = $screen->get_option( 'per_page', 'default' );
        }
        // Default limits.
        if ( '' == $limit ) {
            $limit .= ( 'event_flagged' != $restrict ) ? ' WHERE event_flagged = 0' : '';
        } else {
            $limit .= ( 'event_flagged' != $restrict ) ? ' AND event_flagged = 0' : '';
        }
        if ( isset( $_POST['mcs'] ) || isset( $_GET['s'] ) ) {
            $query  = ( '' != $_POST['mcs'] ) ? $_POST['mcs'] : $_GET['s'];
            $limit .= mc_prepare_search_query( $query );
        }
        $query_limit = ( ( $current - 1 ) * $items_per_page );
        $limit      .= ( 'archived' != $restrict ) ? ' AND event_status = 1' : ' AND event_status = 0';
        if ( 'event_category' != $sortbyvalue ) {
            $events = $wpdb->get_results( $wpdb->prepare( 'SELECT SQL_CALC_FOUND_ROWS event_id FROM ' . my_calendar_table() . " $limit ORDER BY $sortbyvalue $sortbydirection " . 'LIMIT %d, %d', $query_limit, $items_per_page ) ); // WPCS: Unprepared SQL ok.
        } else {
            $limit  = str_replace( array( 'WHERE ' ), '', $limit );
            $limit  = ( strpos( $limit, 'AND' ) === 0 ) ? $limit : 'AND ' . $limit;
            $events = $wpdb->get_results( $wpdb->prepare( 'SELECT DISTINCT SQL_CALC_FOUND_ROWS events.event_id FROM ' . my_calendar_table() . ' AS events JOIN ' . my_calendar_categories_table() . " AS categories WHERE events.event_category = categories.category_id $limit ORDER BY categories.category_name $sortbydirection " . 'LIMIT %d, %d', $query_limit, $items_per_page ) ); // WPCS: Unprepared SQL ok.
        }

        $found_rows = $wpdb->get_col( 'SELECT FOUND_ROWS();' );
        $items      = $found_rows[0];
        $counts     = get_option( 'mc_count_cache' );
        if ( empty( $counts ) ) {
            $counts = mc_update_count_cache();
        }

        $this->total_items = $items;
        $this->items_per_page = $items_per_page;

        foreach ( $events as $e ) {
            $event_table = array();
            $event = mc_get_first_event( $e->event_id );
            if ( ! is_object( $event ) ) {
                continue;
            }

            
            $event_table['ID'] = $event->event_id;
            $event_table['title'] = $this->title_output( $event );
            $event_table['location'] = $this->location_output( $event );
            $event_table['time'] = $this->time_output( $event );
            $event_table['author'] = $this->author_output( $event );
            $event_table['category'] = $this->category_output( $event );
            

            $events_table[] = $event_table;
        }

        return $events_table;
    }

    function prepare_items() {
        
        $this->_column_headers = array(
            $this->get_columns(),
            array(),
            $this->get_sortable_columns()
        );

        $this->process_bulk_actions();
        $this->items = $this->table_data();

        $this->set_pagination_args( array(
            'total_items' => $this->total_items,
            'per_page'    => $this->items_per_page,
            'total_pages' => ceil( $this->total_items / $this->items_per_page )
        ) );
    }

    function category_output( $event ) {
        $output = "";
        if ( ! $event->event_category ) {
            // Events *must* have a category.
            mc_update_event( 'event_category', 1, $event->event_id, '%d' );
        }
        $cat        = mc_get_category_detail( $event->event_category, false );
        $color      = strip_tags( $cat->category_color );
        $color      = ( 0 !== strpos( $color, '#' ) ) ? '#' . $color : $color;
        $categories = mc_get_categories( $event );
        $cats       = array();

        $output .= "<div class='category-color' style='background-color: $color'></div>";
        $output .= "<a class='mc_filter' href='";
        $output .= admin_url( "admin.php?page=my-calendar-manage&amp;filter=$event->event_category&amp;restrict=category" ); 
        $output .= "' title='";
        $output .= __( 'Filter by category', 'my-calendar' ); 
        $output .= "'><span class='screen-reader-text'>";
        $output .= __( 'Show only: ', 'my-calendar' ); 
        $output .= "</span>" . strip_tags( $cat->category_name ) . "</a>";
        
        $string = '';
        if ( is_array( $categories ) ) {
            foreach ( $categories as $category ) {
                if ( $category != $event->event_category ) {
                    $cats[] = mc_get_category_detail( $category, 'category_name' );
                }
                $string = implode( ', ', $cats );
            }
            $output .= ( '' != $string ) ? '(' . $string . ')' : '';
        }

        return $output;
            
    }

    function author_output( $event ) {
        $output = "";
        $author  = ( 0 != $event->event_author ) ? get_userdata( $event->event_author ) : 'Public Submitter';
        $auth   = ( is_object( $author ) ) ? $author->ID : 0;
        $filter = admin_url( "admin.php?page=my-calendar-manage&amp;filter=$auth&amp;restrict=author" );
        $author = ( is_object( $author ) ? $author->display_name : $author );

        $output .= "<a class='mc_filter' href='$filter' title='";
        $output .= __( 'Filter by author', 'my-calendar' );
        $output .= "'>";

        $output .= "<span class='screen-reader-text'>";
        $output .= __( 'Show only: ', 'my-calendar' );
        $output .= "</span>$author</a>";

        return $output;
    }

    function time_output( $event ) {
        $output = "";
        if ( '23:59:59' != $event->event_endtime ) {
            $event_time = date_i18n( get_option( 'mc_time_format' ), mc_strtotime( $event->event_time ) );
        } else {
            $event_time = mc_notime_label( $event );
        }
        $date_format = ( get_option( 'mc_date_format' ) == '' ) ? get_option( 'date_format' ) : get_option( 'mc_date_format' );
        $begin       = date_i18n( $date_format, mc_strtotime( $event->event_begin ) );
        
        $output .= esc_html( "$begin, $event_time" );
        $output .= "<div class='recurs'>";
        $output .= mc_recur_string( $event );
        $output .= "</div>";

        return $output;
    }

    function location_output( $event ) {
        $output = "";
        if ( '' != $event->event_label ) {
            $elabel = urlencode( $event->event_label );
            $output .= "<a class='mc_filter' href='";
            $output .= admin_url( "admin.php?page=my-calendar-manage&amp;filter=$elabel&amp;restrict=where" );
            $output .= "' title='";
            $output .= __( 'Filter by location', 'my-calendar' );
            $output .= "'><span class='screen-reader-text'>";
            $output .= __( 'Show only: ', 'my-calendar' );
            $output .= "</span>";
            $output .= strip_tags( stripslashes( $event->event_label ) );
            $output .= "</a>";
        }

        return $output;
    }

    function title_output( $event ) {
        $output = "<strong>";
        $pending = ( 0 == $event->event_approved ) ? 'pending' : '';
        $trashed = ( 2 == $event->event_approved ) ? 'trashed' : '';
        $trash      = ( '' != $trashed ) ? ' - ' . __( 'Trash', 'my-calendar' ) : '';
        $draft      = ( '' != $pending ) ? ' - ' . __( 'Draft', 'my-calendar' ) : $trash;
        $edit_url   = admin_url( "admin.php?page=my-calendar&amp;mode=edit&amp;event_id=$event->event_id" );
        $copy_url   = admin_url( "admin.php?page=my-calendar&amp;mode=copy&amp;event_id=$event->event_id" );
        $view_url   = mc_get_details_link( $event );
        $group_url  = admin_url( "admin.php?page=my-calendar-groups&amp;mode=edit&amp;event_id=$event->event_id&amp;group_id=$event->event_group_id" );
        $delete_url = admin_url( "admin.php?page=my-calendar-manage&amp;mode=delete&amp;event_id=$event->event_id" );
        $can_edit   = mc_can_edit_event( $event );
        $actions = array();

        if ( $can_edit ) {
            $output .= "<a href='$edit_url' class='edit'><span class='dashicons dashicons-edit' aria-hidden='true'></span>";
        }

        if ( 1 == $event->event_flagged && ( isset( $_GET['restrict'] ) && 'flagged' == $_GET['restrict'] ) ) {
            $spam       = 'spam';
            $pending    = '';
            $spam_label = '<strong>' . __( 'Possible spam', 'my-calendar' ) . ':</strong> ';
        } else {
            $spam       = '';
            $spam_label = '';
        }
        $output .= $spam_label;
        
        $output .= strip_tags( stripslashes( $event->event_title ) );
        if ( $can_edit ) {
            $output .= "</a>";
            $check      = mc_test_occurrence_overlap( $event, true );
            if ( '' != $check ) {
                // Translators: URL to edit event.
                $output .= "<br /><strong class='error'>";
                $output .= sprintf( __( 'There is a problem with this event. <a href="%s">Edit</a>', 'my-calendar' ), $edit_url );
                $output .= "</strong>";
            }
        }
        $output .= $draft;
        $output .= "</strong>";

        // Building actions links.
        if ( mc_event_published( $event ) ) {
            $actions['view'] = sprintf( '<a href="%s" class="view">%s</a>', $view_url, __( 'View', 'my-calendar' ) );
        } elseif ( current_user_can( 'mc_manage_events' ) ) {
            $actions['view'] = sprintf( '<a href="%s" class="view">%s</a>', add_query_arg( 'preview', 'true', $view_url ), __( 'Preview', 'my-calendar' ) );
        }

        $actions['copy'] = sprintf( '<a href="%s" class="copy">%s</a>', $copy_url, __( 'Copy', 'my-calendar' ) );
        
        if ( $can_edit ) {
            if ( mc_event_is_grouped( $event->event_group_id ) ) {
                $actions['edit_group'] = sprintf( '<a href="%s" class="edit_group">%s</a>', $group_url, __( 'Edit Group', 'my-calendar' ) );
            }
            $actions['delete'] = sprintf( '<a href="%s" class="delete">%s</a>', $delete_url, __( 'Delete', 'my-calendar' ) );
        } else {
            $actions[] = __( 'Not editable.', 'my-calendar' );
        }

        if ( current_user_can( 'mc_approve_events' ) ) {
            if ( 1 == $event->event_approved ) {
                $mo = 'reject';
                $te = __( 'Trash', 'my-calendar' );
            } else {
                $mo = 'publish';
                $te = __( 'Publish', 'my-calendar' );
            }
            $approve_url = admin_url( "admin.php?page=my-calendar-manage&amp;mode=$mo&amp;event_id=$event->event_id" );
            $actions[$mo] = sprintf( '<a href="%s" class="%s">%s</a>', $approve_url, $mo, $te );
        } else {
            switch ( $event->event_approved ) {
                case 1:
                    $actions[] = __( 'Published', 'my-calendar' );
                    break;
                case 2:
                    $actions[] = __( 'Trashed', 'my-calendar' );
                    break;
                default:
                    $actions[] = __( 'Awaiting Approval', 'my-calendar' );
            }
        }

        return sprintf('%1$s %2$s', $output, $this->row_actions($actions) );
    }

    function links_output() {
        $counts     = get_option( 'mc_count_cache' );
		if ( empty( $counts ) ) {
			$counts = mc_update_count_cache();
        }

        $allow_filters = ( ! current_user_can( 'mc_manage_events' ) && 
                           ! current_user_can( 'mc_approve_events' ) ) ? false : true;

        ?>
		<ul class="links">
			<li>
				<a <?php echo ( isset( $_GET['limit'] ) && 'published' == $_GET['limit'] ) ? 'class="active-link" aria-current="true"' : ''; ?>
					href="<?php echo admin_url( 'admin.php?page=my-calendar-manage&amp;limit=published' ); ?>">
				<?php
					// Translators: Number of published events.
					printf( __( 'Published (%d)', 'my-calendar' ), $counts['published'] );
				?>
				</a>
			</li>
			<li>
				<a <?php echo ( isset( $_GET['limit'] ) && 'draft' == $_GET['limit'] ) ? 'class="active-link" aria-current="true"' : ''; ?>
					href="<?php echo admin_url( 'admin.php?page=my-calendar-manage&amp;limit=draft' ); ?>">
				<?php
					// Translators: Number of draft events.
					printf( __( 'Drafts (%d)', 'my-calendar' ), $counts['draft'] );
				?>
				</a>
			</li>
			<li>
				<a <?php echo ( isset( $_GET['limit'] ) && 'trashed' == $_GET['limit'] ) ? 'class="active-link" aria-current="true"' : ''; ?>
					href="<?php echo admin_url( 'admin.php?page=my-calendar-manage&amp;limit=trashed' ); ?>">
				<?php
					// Translators: Number of trashed events.
					printf( __( 'Trash (%d)', 'my-calendar' ), $counts['trash'] );
				?>
				</a>
			</li>
			<li>
				<a <?php echo ( isset( $_GET['restrict'] ) && 'archived' == $_GET['restrict'] ) ? 'class="active-link" aria-current="true"' : ''; ?>
					href="<?php echo admin_url( 'admin.php?page=my-calendar-manage&amp;restrict=archived' ); ?>">
				<?php
					// Translators: Number of archived events.
					printf( __( 'Archived (%d)', 'my-calendar' ), $counts['archive'] );
				?>
				</a>
			</li>
			<?php
			if ( function_exists( 'akismet_http_post' ) && $allow_filters ) {
				?>
			<li>
				<a <?php echo ( isset( $_GET['restrict'] ) && 'flagged' == $_GET['restrict'] ) ? 'class="active-link" aria-current="true"' : ''; ?>
					href="<?php echo admin_url( 'admin.php?page=my-calendar-manage&amp;restrict=flagged&amp;filter=1' ); ?>">
				<?php
					// Translators: Number of events marked as spam.
					printf( __( 'Spam (%d)', 'my-calendar' ), $counts['spam'] );
				?>
				</a>
			</li>
				<?php
			}
			?>
			<li>
				<a <?php echo ( isset( $_GET['limit'] ) && 'all' == $_GET['limit'] || ( ! isset( $_GET['limit'] ) && ! isset( $_GET['restrict'] ) ) ) ? 'class="active-link" aria-current="true"' : ''; ?>
					href="<?php echo admin_url( 'admin.php?page=my-calendar-manage&amp;limit=all' ); ?>"><?php _e( 'All', 'my-calendar' ); ?></a>
			</li>
		</ul>
		<?php
    }

}
?>