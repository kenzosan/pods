<?php
/**
 * Component managing class
 *
 * @package Pods
 */
class PodsComponents {

    /**
     * Root of Components directory
     *
     * @var string
     *
     * @private
     * @since 2.0.0
     */
    private $components_dir = null;

    /**
     * Available components
     *
     * @var string
     *
     * @since 2.0.0
     */
    public $components = array();

    /**
     * Components settings
     *
     * @var string
     *
     * @since 2.0.0
     */
    public $settings = array();

    /**
     * Setup actions and get options
     *
     * @since 2.0.0
     */
    public function __construct () {
        $this->components_dir = realpath( apply_filters( 'pods_components_dir', PODS_DIR . 'components' ) ) . '/';

        $settings = get_option( 'pods_component_settings', '' );

        if ( !empty( $settings ) )
            $this->settings = (array) json_decode( $settings, true );

        if ( !isset( $this->settings[ 'components' ] ) )
            $this->settings[ 'components' ] = array();

        // Get components (give it access to theme)
        add_action( 'setup_theme', array( $this, 'get_components' ), 11 );

        // Load in components
        add_action( 'setup_theme', array( $this, 'load' ), 12 );

        // AJAX handling
        if ( is_admin() ) {
            add_action( 'wp_ajax_pods_admin_components', array( $this, 'admin_ajax' ) );
            add_action( 'wp_ajax_nopriv_pods_admin_components', array( $this, 'admin_ajax' ) );

            // Add the Pods Components capabilities
            add_filter( 'members_get_capabilities', array( $this, 'admin_capabilities' ) );
        }
    }

    /**
     * Add menu item
     *
     * @param string $parent The parent slug.
     *
     * @since 2.0.0
     *
     * @uses add_submenu_page
     */
    public function menu ( $parent ) {
        global $submenu;

        $custom_component_menus = array();

        foreach ( $this->components as $component => $component_data ) {
            if ( empty( $component_data[ 'MustUse' ] ) && ( !isset( $this->settings[ 'components' ][ $component ] ) || 0 == $this->settings[ 'components' ][ $component ] ) )
                continue;

            if ( !empty( $component_data[ 'Hide' ] ) )
                continue;

            if ( !empty( $component_data[ 'DeveloperMode' ] ) && !pods_developer() )
                continue;

            if ( empty( $component_data[ 'MenuPage' ] ) ) {
                if ( !isset( $component_data[ 'object' ] ) )
                    continue;
                elseif ( !method_exists( $component_data[ 'object' ], 'admin' ) && !method_exists( $component_data[ 'object' ], 'options' ) )
                    continue;
            }

            if ( false === $component_data[ 'External' ] )
                $component_data[ 'File' ] = realpath( $this->components_dir . $component_data[ 'File' ] );

            if ( !file_exists( $component_data[ 'File' ] ) ) {
                pods_message( 'Pods Component not found: ' . $component_data[ 'File' ] );

                pods_transient_clear( 'pods_components' );

                continue;
            }

            $capability = 'pods_component_' . str_replace( '-', '_', sanitize_title( $component ) );

            if ( 0 < strlen( $component_data[ 'Capability' ] ) )
                $capability = $component_data[ 'Capability' ];

            if ( !is_super_admin() && !current_user_can( 'delete_users' ) && !current_user_can( 'pods' ) && !current_user_can( 'pods_components' ) && !current_user_can( $capability ) )
                continue;

            $menu_page = 'pods-component-' . $component;

            if ( !empty( $component_data[ 'MenuPage' ] ) )
                $custom_component_menus[ $menu_page ] = $component_data;

            $page = add_submenu_page(
                $parent,
                strip_tags( $component_data[ 'Name' ] ),
                '- ' . strip_tags( $component_data[ 'MenuName' ] ),
                'read',
                $menu_page,
                array( $this, 'admin_handler' )
            );

            if ( isset( $component_data[ 'object' ] ) && method_exists( $component_data[ 'object' ], 'admin_assets' ) )
                add_action( 'admin_print_styles-' . $page, array( $component_data[ 'object' ], 'admin_assets' ) );
        }

        if ( !empty( $custom_component_menus ) ) {
            foreach ( $custom_component_menus as $menu_page => $component_data ) {
                if ( isset( $submenu[ $parent ] ) ) {
                    foreach ( $submenu[ $parent ] as $sub => &$menu ) {
                        if ( $menu[ 2 ] == $menu_page ) {
                            $menu_page = $component_data[ 'MenuPage' ];

                            /*if ( !empty( $component_data[ 'MenuAddPage' ] ) ) {
                                if ( false !== strpos( $_SERVER[ 'REQUEST_URI' ], $component_data[ 'MenuAddPage' ] ) )
                                    $menu_page = $component_data[ 'MenuAddPage' ];
                            }*/

                            $menu[ 2 ] = $menu_page;

                            $page = current( explode( '?', $menu[ 2 ] ) );

                            if ( isset( $component_data[ 'object' ] ) && method_exists( $component_data[ 'object' ], 'admin_assets' ) )
                                add_action( 'admin_print_styles-' . $page, array( $component_data[ 'object' ], 'admin_assets' ) );

                            break;
                        }
                    }
                }
            }
        }
    }

    /**
     * Load activated components and init component
     *
     * @since 2.0.0
     */
    public function load () {
        do_action( 'pods_components_load' );

        foreach ( (array) $this->components as $component => $component_data ) {
            if ( false === $component_data[ 'MustUse' ] && ( !isset( $this->settings[ 'components' ][ $component ] ) || 0 == $this->settings[ 'components' ][ $component ] ) )
                continue;

            if ( !empty( $component_data[ 'PluginDependency' ] ) ) {
                $dependency = explode( '|', $component_data[ 'PluginDependency' ] );

                if ( !pods_is_plugin_active( $dependency[ 1 ] ) )
                    continue;
            }

            if ( !empty( $component_data[ 'ThemeDependency' ] ) ) {
                $dependency = explode( '|', $component_data[ 'ThemeDependency' ] );

                if ( strtolower( $dependency[ 1 ] ) != strtolower( get_template() ) && strtolower( $dependency[ 1 ] ) != strtolower( get_stylesheet() ) )
                    continue;
            }

            if ( false === $component_data[ 'External' ] )
                $component_data[ 'File' ] = realpath( $this->components_dir . $component_data[ 'File' ] );

            if ( empty( $component_data[ 'File' ] ) ) {
                pods_transient_clear( 'pods_components' );

                continue;
            }

            if ( !file_exists( $component_data[ 'File' ] ) ) {
                pods_message( 'Pods Component not found: ' . $component_data[ 'File' ] );

                pods_transient_clear( 'pods_components' );

                continue;
            }

            include_once $component_data[ 'File' ];

            if ( ( !empty( $component_data[ 'Class' ] ) && class_exists( $component_data[ 'Class' ] ) ) || isset( $component_data[ 'object' ] ) ) {
                if ( !isset( $this->components[ $component ][ 'object' ] ) )
                    $this->components[ $component ][ 'object' ] = new $component_data[ 'Class' ];

                if ( method_exists( $this->components[ $component ][ 'object' ], 'options' ) ) {
                    if ( isset( $this->settings[ 'components' ][ $component ] ) )
                        $this->components[ $component ][ 'options' ] = $this->components[ $component ][ 'object' ]->options( $this->settings[ 'components' ][ $component ] );
                    else
                        $this->components[ $component ][ 'options' ] = $this->components[ $component ][ 'object' ]->options( array() );

                    $this->options( $component, $this->components[ $component ][ 'options' ] );
                }
                else
                    $this->options( $component, array() );

                if ( method_exists( $this->components[ $component ][ 'object' ], 'handler' ) )
                    $this->components[ $component ][ 'object' ]->handler( $this->settings[ 'components' ][ $component ] );
            }
        }
    }

    /**
     * Get list of components available
     *
     * @since 2.0.0
     */
    public function get_components () {
        $components = pods_transient_get( 'pods_components' );

        if ( 1 == pods_var( 'pods_debug_components', 'get', 0 ) && is_user_logged_in() && ( is_super_admin() || current_user_can( 'delete_users' ) || current_user_can( 'pods' ) ) )
            $components = array();

        if ( PodsInit::$version != PODS_VERSION || !is_array( $components ) || empty( $components ) || ( is_admin() && isset( $_GET[ 'page' ] ) && 'pods-components' == $_GET[ 'page' ] && 1 !== pods_transient_get( 'pods_components_refresh' ) ) ) {
            do_action( 'pods_components_get' );

            $component_dir = @opendir( untrailingslashit( $this->components_dir ) );
            $component_files = array();

            if ( false !== $component_dir ) {
                while ( false !== ( $file = readdir( $component_dir ) ) ) {
                    if ( '.' == substr( $file, 0, 1 ) )
                        continue;
                    elseif ( is_dir( $this->components_dir . $file ) ) {
                        $component_subdir = @opendir( $this->components_dir . $file );

                        if ( $component_subdir ) {
                            while ( false !== ( $subfile = readdir( $component_subdir ) ) ) {
                                if ( '.' == substr( $subfile, 0, 1 ) )
                                    continue;
                                elseif ( '.php' == substr( $subfile, -4 ) )
                                    $component_files[] = str_replace( '\\', '/', $file . '/' . $subfile );
                            }

                            closedir( $component_subdir );
                        }
                    }
                    elseif ( '.php' == substr( $file, -4 ) )
                        $component_files[] = $file;
                }

                closedir( $component_dir );
            }

            $default_headers = array(
                'ID' => 'ID',
                'Name' => 'Name',
                'ShortName' => 'Short Name',
                'PluginName' => 'Plugin Name',
                'ComponentName' => 'Component Name',
                'URI' => 'URI',
                'MenuName' => 'Menu Name',
                'MenuPage' => 'Menu Page',
                'MenuAddPage' => 'Menu Add Page',
                'MustUse' => 'Must Use',
                'Description' => 'Description',
                'Version' => 'Version',
                'Category' => 'Category',
                'Author' => 'Author',
                'AuthorURI' => 'Author URI',
                'Class' => 'Class',
                'Hide' => 'Hide',
                'PluginDependency' => 'Plugin Dependency',
                'ThemeDependency' => 'Theme Dependency',
                'DeveloperMode' => 'Developer Mode',
                'Capability' => 'Capability'
            );

            $component_files = apply_filters( 'pods_components_register', $component_files );

            $components = array();

            foreach ( $component_files as $component_file ) {
                $external = false;

                if ( is_array( $component_file ) && isset( $component_file[ 'File' ] ) ) {
                    $component = $component_file = $component_file[ 'File' ];

                    $external = true;
                }
                else
                    $component = $this->components_dir . $component_file;

                if ( !is_readable( $component ) )
                    continue;

                $component_data = get_file_data( $component, $default_headers, 'pods_component' );

                if ( ( empty( $component_data[ 'Name' ] ) && empty( $component_data[ 'ComponentName' ] ) && empty( $component_data[ 'PluginName' ] ) ) || 'yes' == $component_data[ 'Hide' ] )
                    continue;

                if ( empty( $component_data[ 'Name' ] ) ) {
                    if ( !empty( $component_data[ 'ComponentName' ] ) )
                        $component_data[ 'Name' ] = $component_data[ 'ComponentName' ];
                    elseif ( !empty( $component_data[ 'PluginName' ] ) )
                        $component_data[ 'Name' ] = $component_data[ 'PluginName' ];
                }

                if ( empty( $component_data[ 'ShortName' ] ) )
                    $component_data[ 'ShortName' ] = $component_data[ 'Name' ];

                if ( empty( $component_data[ 'MenuName' ] ) )
                    $component_data[ 'MenuName' ] = $component_data[ 'Name' ];

                if ( empty( $component_data[ 'Class' ] ) )
                    $component_data[ 'Class' ] = 'Pods_' . pods_clean_name( basename( $component, '.php' ), false );

                if ( empty( $component_data[ 'ID' ] ) )
                    $component_data[ 'ID' ] = sanitize_title( $component_data[ 'Name' ] );

                if ( 'on' == strtolower( $component_data[ 'DeveloperMode' ] ) || 1 == $component_data[ 'DeveloperMode' ] )
                    $component_data[ 'DeveloperMode' ] = true;
                else
                    $component_data[ 'DeveloperMode' ] = false;

                $component_data[ 'External' ] = (boolean) $external;

                if ( 'on' == strtolower($component_data[ 'MustUse' ] ) || '1' == $component_data[ 'MustUse' ] )
                    $component_data[ 'MustUse' ] = true;
                elseif ( 'off' == strtolower($component_data[ 'MustUse' ] ) || '0' == $component_data[ 'MustUse' ] )
                    $component_data[ 'MustUse' ] = false;
                else
                    $component_data[ 'MustUse' ] = $component_data[ 'External' ];


                $component_data[ 'File' ] = $component_file;

                $components[ $component_data[ 'ID' ] ] = $component_data;
            }

            ksort( $components );

            pods_transient_set( 'pods_components_refresh', 1, ( 60 * 60 * 12 ) );

            pods_transient_set( 'pods_components', $components );
        }

        if ( 1 == pods_var( 'pods_debug_components', 'get', 0 ) && is_user_logged_in() && ( is_super_admin() || current_user_can( 'delete_users' ) || current_user_can( 'pods' ) ) )
            pods_debug( $components );

        $this->components = $components;

        return $this->components;
    }

    /**
     * Set component options
     *
     * @param $component
     * @param $options
     *
     * @since 2.0.0
     */
    public function options ( $component, $options ) {
        if ( !isset( $this->settings[ 'components' ][ $component ] ) || !is_array( $this->settings[ 'components' ][ $component ] ) )
            $this->settings[ 'components' ][ $component ] = array();

        foreach ( $options as $option => $data ) {
            if ( !isset( $this->settings[ 'components' ][ $component ][ $option ] ) && isset( $data[ 'default' ] ) )
                $this->settings[ 'components' ][ $component ][ $option ] = $data[ 'default' ];
        }
    }

    /**
     * Call component specific admin functions
     *
     * @since 2.0.0
     */
    public function admin_handler () {
        $component = str_replace( 'pods-component-', '', $_GET[ 'page' ] );

        if ( isset( $this->components[ $component ] ) && isset( $this->components[ $component ][ 'object' ] ) && is_object( $this->components[ $component ][ 'object' ] ) ) {
            if ( method_exists( $this->components[ $component ][ 'object' ], 'admin' ) )
                $this->components[ $component ][ 'object' ]->admin( $this->settings[ 'components' ][ $component ], $component );
            elseif ( method_exists( $this->components[ $component ][ 'object' ], 'options' ) )
                $this->admin( $this->components[ $component ][ 'object' ]->options( $this->settings[ 'components' ][ $component ] ), $this->settings[ 'components' ][ $component ], $component );
        }
    }

    public function admin ( $options, $settings, $component ) {
        if ( !isset( $this->components[ $component ] ) )
            wp_die( 'Invalid Component' );

        $component_label = $this->components[ $component ][ 'Name' ];

        include PODS_DIR . 'ui/admin/components-admin.php';
    }

    /**
     * Toggle a component on or off
     *
     * @param string $component The component name to toggle
     *
     * @return bool
     *
     * @since 2.0.0
     */
    public function toggle ( $component ) {
        $toggle = null;

        if ( isset( $this->components[ $component ] ) ) {
            if ( 1 == pods_var( 'toggle', 'get' ) && ( !isset( $this->settings[ 'components' ][ $component ] ) || 0 == $this->settings[ 'components' ][ $component ] ) ) {
                $this->settings[ 'components' ][ $component ] = array();
                $toggle = true;
            }
            elseif ( 0 == pods_var( 'toggle', 'get' ) ) {
                $this->settings[ 'components' ][ $component ] = 0;
                $toggle = false;
            }
        }

        $settings = version_compare( PHP_VERSION, '5.4.0', '>=' ) ? json_encode( $this->settings, JSON_UNESCAPED_UNICODE ) : json_encode( $this->settings );

        update_option( 'pods_component_settings', $settings );

        return $toggle;
    }

    /**
     * Add pods specific capabilities.
     *
     * @param $capabilities List of extra capabilities to add
     *
     * @return array
     */
    public function admin_capabilities ( $capabilities ) {
        foreach ( $this->components as $component => $component_data ) {
            if ( !empty( $component_data[ 'Hide' ] ) )
                continue;

            if ( true === (boolean) pods_var( 'DeveloperMode', $component_data, false ) && ( !pods_developer() ) )
                continue;

            if ( empty( $component_data[ 'MenuPage' ] ) && ( !isset( $component_data[ 'object' ] ) || !method_exists( $component_data[ 'object' ], 'admin' ) ) )
                continue;

            $capability = 'pods_component_' . str_replace( '-', '_', sanitize_title( str_replace( ' and ', ' ', strip_tags( $component_data[ 'Name' ] ) ) ) );

            if ( 0 < strlen ( $component_data[ 'Capability' ] ) )
                $capability = $component_data[ 'Capability' ];

            if ( !in_array( $capability, $capabilities ) )
                $capabilities[] = $capability;
        }

        return $capabilities;
    }

    /**
     * Handle admin ajax
     *
     * @since 2.0.0
     */
    public function admin_ajax () {
        if ( false === headers_sent() ) {
            if ( '' == session_id() )
                @session_start();

            header( 'Content-Type: text/html; charset=' . get_bloginfo( 'charset' ) );
        }

        // Sanitize input
        $params = stripslashes_deep( (array) $_POST );

        foreach ( $params as $key => $value ) {
            if ( 'action' == $key )
                continue;

            unset( $params[ $key ] );

            $params[ str_replace( '_podsfix_', '', $key ) ] = $value;
        }

        $params = (object) $params;

        $component = $params->component;
        $method = $params->method;

        if ( !isset( $component ) || !isset( $this->components[ $component ] ) || !isset( $this->settings[ 'components' ][ $component ] ) )
            pods_error( 'Invalid AJAX request', $this );

        if ( !isset( $params->_wpnonce ) || false === wp_verify_nonce( $params->_wpnonce, 'pods-component-' . $component . '-' . $method ) )
            pods_error( 'Unauthorized request', $this );

        // Cleaning up $params
        unset( $params->action );
        unset( $params->component );
        unset( $params->method );
        unset( $params->_wpnonce );

        $params = (object) apply_filters( 'pods_component_ajax_' . $component . '_' . $method, $params, $component, $method );

        $output = false;

        // Handle internal methods
        if ( isset( $this->components[ $component ][ 'object' ] ) && !method_exists( $this->components[ $component ][ 'object' ], 'ajax_' . $method ) && method_exists( $this, 'admin_ajax_' . $method ) )
            $output = call_user_func( array( $this, 'admin_ajax_' . $method ), $component, $params );
        // Make sure method exists
        elseif ( !isset( $this->components[ $component ][ 'object' ] ) || !method_exists( $this->components[ $component ][ 'object' ], 'ajax_' . $method ) )
            pods_error( 'API method does not exist', $this );
        // Dynamically call the component method
        else
            $output = call_user_func( array( $this->components[ $component ][ 'object' ], 'ajax_' . $method ), $params );

        if ( !is_bool( $output ) )
            echo $output;

        die(); // KBAI!
    }

    public function admin_ajax_settings ( $component, $params ) {
        if ( !isset( $this->components[ $component ] ) )
            wp_die( 'Invalid Component' );
        elseif ( !method_exists( $this->components[ $component ][ 'object' ], 'options' ) )
            pods_error( 'Component options method does not exist', $this );

        $options = $this->components[ $component ][ 'object' ]->options( $this->settings[ 'components' ][ $component ] );

        if ( empty( $this->settings[ 'components' ][ $component ] ) )
            $this->settings[ 'components' ][ $component ] = array();

        foreach ( $options as $field_name => $field_option ) {
            $field_option = PodsForm::field_setup( $field_option, null, $field_option[ 'type' ] );

            if ( !is_array( $field_option[ 'group' ] ) ) {
                $field_value = pods_var_raw( 'pods_setting_' . $field_name, $params );

                $this->settings[ 'components' ][ $component ][ $field_name ] = $field_value;
            }
            else {
                foreach ( $field_option[ 'group' ] as $field_group_name => $field_group_option ) {
                    $field_value = pods_var_raw( 'pods_setting_' . $field_group_name, $params );

                    $this->settings[ 'components' ][ $component ][ $field_group_name ] = $field_value;
                }
            }
        }

        $settings = version_compare( PHP_VERSION, '5.4.0', '>=' ) ? json_encode( $this->settings, JSON_UNESCAPED_UNICODE ) : json_encode( $this->settings );

        update_option( 'pods_component_settings', $settings );

        return '1';
    }
}

/**
 * The base component class, all components should extend this.
 *
 * @package Pods
 */
class PodsComponent {

    /**
     * Do things like register/enqueue scripts and stylesheets
     *
     * @since 2.0.0
     */
    public function __construct () {

    }

    /**
     * Add options and set defaults for component settings, shows in admin area
     *
     * @return array $options
     *
     * @since 2.0.0
    public function options () {
        $options = array(
            'option_name' => array(
                'label' => 'Option Label',
                'depends-on' => array( 'another_option' => 'specific-value' ),
                'default' => 'default-value',
                'type' => 'field_type',
                'data' => array(
                    'value1' => 'Label 1',

                    // Group your options together
                    'Option Group' => array(
                        'gvalue1' => 'Option Label 1',
                        'gvalue2' => 'Option Label 2'
                    ),

                    // below is only if the option_name above is the "{$fieldtype}_format_type"
                    'value2' => array(
                        'label' => 'Label 2',
                        'regex' => '[a-zA-Z]' // Uses JS regex validation for the value saved if this option selected
                    )
                ),

                // below is only for a boolean group
                'group' => array(
                    'option_boolean1' => array(
                        'label' => 'Option boolean 1?',
                        'default' => 1,
                        'type' => 'boolean'
                    ),
                    'option_boolean2' => array(
                        'label' => 'Option boolean 2?',
                        'default' => 0,
                        'type' => 'boolean'
                    )
                )
            )
        );

        return $options;
    }
    */

    /**
     * Handler to run code based on $options
     *
     * @param $options
     *
     * @since 2.0.0
     */
    public function handler ( $options ) {
        // run code based on $options set
    }

    /**
     * Build admin area
     *
     * @param $options
     *
     * @since 2.0.0
    public function admin ( $options ) {
    // run code based on $options set
    }
     */
}
