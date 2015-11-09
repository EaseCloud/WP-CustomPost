<?php

/**
 * 自定义类，派生使用，派生时需要写入 static 参数进行配置。
 * 然后需要调用子类的 init 方法。
 */
class CustomPost {

    // 配置选项（继承时必须填入这些属性）
    public static $post_type;
    public static $post_type_name;
    public static $post_type_description = '';
    public static $post_type_supports =
        array('title', 'thumbnail', 'excerpt', 'editor', 'comments');
    public static $menu_icon = 'dashicons-admin-post';
    public static $capabilities = array();

    // 实有成员
    public $post;

    /** 构造函数
     * @param $post
     */
    public function __construct($post) {

        // 构造 $post 对象
        if($post instanceof WP_Post) {
            // 直接使用 post 对象构造的情况
            $this->post = $post;
        } else {
            // 使用 post_ID 构造的情况
            $this->post = get_post($post);
        }

        // 校验 $post 的类型
        assert(
            $this->post && $this->post->post_type == static::$post_type,
            __('Constructing post type in not correct, should be of type: ', WCP_DOMAIN)
            .static::$post_type
        );

    }

    public function __toString() {
        return strval($this->post->post_title);
    }

    // 执行动态属性的读写为 post_meta 的读写
    public function __get($key) {
        return get_post_meta($this->post->ID, $key, true);
    }

    // 执行动态属性的读写为 post_meta 的读写
    public function __set($key, $val) {
        update_post_meta($this->post->ID, $key, $val);
    }

    /**
     * 获取当前 Post 的作者 User 对象
     */
    function getAuthor() {
        return new WP_User($this->post->post_author);
    }

    // 获取当前商品的特色图像，如果没有设置返回默认图。
    public function getThumbnailUrl() {
        $img_id = get_post_thumbnail_id($this->post->ID);
        if(!$img_id) return get_template_directory_uri().'/images/thumbnail.png';
        $img = wp_get_attachment_image_src($img_id, 'full');
        return $img[0];
    }

    // 初始化脚本，完成 post_type 注册等工作，派生该类之后，如果需要使用必须手动先执行一次
    public static function init() {

        // 需要获取调用的子类
        $class = get_called_class();

        // 需要调入闭包的变量
        add_action('init', function() use ($class) {

            register_post_type($class::$post_type, array(
                'label' => $class::$post_type_name,
                'description' => $class::$post_type_description,
                'labels' => array(
                    'name' => $class::$post_type_name,
                    'singular_name' => $class::$post_type_name,
                    'menu_name' => $class::$post_type_name,
                    'parent_item_colon'   => __('Parent ', WCP_DOMAIN).$class::$post_type_name,
                    'all_items' => __('All ', WCP_DOMAIN).$class::$post_type_name_plural,
                    'view_item' => __('View ', WCP_DOMAIN),
                    'add_new_item' => __('Add ', WCP_DOMAIN).$class::$post_type_name,
                    'add_new' => __('Add ', WCP_DOMAIN).$class::$post_type_name,
                    'edit_item' => __('Edit ', WCP_DOMAIN).$class::$post_type_name,
                    'update_item' => __('Update', WCP_DOMAIN),
                    'search_items' => __('Search ', WCP_DOMAIN).$class::$post_type_name,
                    'not_found' => __('There is no data here.', WCP_DOMAIN),
                    'not_found_in_trash' => __('No items in trash.', WCP_DOMAIN),
                ),
                'supports' => $class::$post_type_supports,
//                'taxonomies' => array('region', 'industry'),
                'hierarchical' => false,
                'public' => true,
                'show_ui' => true,
                'show_in_menu' => true,
                'show_in_nav_menus' => true,
                'show_in_admin_bar' => true,
                'menu_position' => 5,
                'can_export' => true,
                'has_archive' => true,
                'exclude_from_search' => false,
                'publicly_queryable' => true,
                'menu_icon' => $class::$menu_icon,
                'rewrite' => array(
                    'slug' => $class::$post_type,
                    'with_front' => true,
                ),
//                'capability_type' => $class::$post_type,
                'capabilities' => $class::$capabilities,
            ));

        }, 10, 0);

    }


    /**
     * 获取当前 post 对象对应的所有 CustomTaxonomy 关联的对象
     * @param string $taxonomy 指定的 Taxonomy 别名或者类名
     * @return array 如果指定了 $taxonomy，返回一个 CustomTaxonomy 的纯数组
     * 如果没有指定，根据 $taxonomy 的别名分别指定数据返回
     */
    public function terms($taxonomy=null) {

        // CustomTaxonomy 子类分析
        $classes = array();
        foreach(get_declared_classes() as $cls) {
            // 找到所有 CustomTaxonomy 的子类
            if(is_subclass_of($cls, 'CustomTaxonomy')) {
                $tax = get_taxonomy($cls::$taxonomy);
                // 校验指定的 $post_type 是否与当前 taxonomy 关联
                if(!in_array(static::$post_type, $tax->object_type)) continue;
                // 如果指定了 $post_type，跳过所有不匹配的部分
                if($taxonomy && $taxonomy != $cls &&
                    $taxonomy != $cls::$taxonomy) continue;

                $classes []= $cls;
            }
        }

        // 如果指定了 $taxonomy，校验完整性
        assert(
            !$taxonomy || sizeof($taxonomy) === 1,
            __('Specified CustomTaxonomy not found.', WCP_DOMAIN)
        );

        // 逐个 post_type 进行查询
        $result = array();

        foreach($classes as $cls) {
            $result[$cls::$taxonomy] = array_map(function($term) use ($cls) {
                return new $cls($term);
            }, get_the_terms($this->post, $cls::$taxonomy)
            );
        }

        return $taxonomy ? $result[$classes[0]::$taxonomy] : $result;

    }

}


class CustomTaxonomy {

    // 配置选项（继承时必须填入这些属性）
    public static $taxonomy;  // tax 的别名
    public static $taxonomy_name;  // tax 的显示名称
    public static $taxonomy_name_plural;  // tax 的显示名称（复数）
    public static $post_types = array();  // tax 匹配的 post type
    public static $capabilities = array();
    public static $show_ui = true;
    public static $show_admin_column = true;

    // 实有成员
    public $term;

    /** 构造函数
     * @param $term
     */
    public function __construct($term) {

        // 构造 $term 对象
        if($term instanceof stdClass && $term->term_id) {
            // 直接使用 term 对象构造的情况
            $this->term = $term;
        } elseif (is_int($term)) {
            // 使用 term_ID 构造的情况
            $this->term = get_term($term, static::$taxonomy);
        } else {
            // 使用 slug 构造
            $this->term = get_term_by('slug', $term, static::$taxonomy);
        }

        assert(
            $this->term && $this->term->taxonomy == static::$taxonomy,
            __('Constructing term type is not correct, should be of [', WCP_DOMAIN)
            .static::$taxonomy.__('] taxonomy.', WCP_DOMAIN)
        );

    }

    function __toString() {
        return strval($this->term->name);
    }

    // 初始化脚本，完成 taxonomy 注册等工作，派生该类之后，如果需要使用必须手动先执行一次
    public static function init() {

        // 需要获取调用的子类
        $class = get_called_class();

        // 需要调入闭包的变量
        add_action('init', function() use ($class) {

            register_taxonomy($class::$taxonomy, $class::$post_types, array(
                'hierarchical' => true, // $class::$hierarchical,
                'show_ui' => $class::$show_ui,
                'show_admin_column' => $class::$show_admin_column,
                'query_var' => true,
                'rewrite' => array('slug' => $class::$taxonomy),
                'capabilities' => $class::$capabilities,
                'labels' => array(
                    'name' => $class::$taxonomy_name,
                    'singular_name' => $class::$taxonomy_name,
                    'search_items' => __('Search ', WCP_DOMAIN).$class::$taxonomy_name,
                    'all_items' => __('All ', WCP_DOMAIN).$class::$taxonomy_name_plural,
                    'parent_item' => __('Parent ', WCP_DOMAIN).$class::$taxonomy_name,
                    'parent_item_colon'   => __('Parent ', WCP_DOMAIN).$class::$taxonomy_name,
                    'edit_item' => __('Edit ', WCP_DOMAIN).$class::$taxonomy_name,
                    'update_item' => __('Update', WCP_DOMAIN),
                    'add_new_item' => __('Add ', WCP_DOMAIN).$class::$taxonomy_name,
                    'new_item_name' => __('Add ', WCP_DOMAIN).$class::$taxonomy_name,
                    'menu_name' => $class::$taxonomy_name,
                ),
            ));

        }, 10, 0);

    }


    /**
     * 返回当前定义的 Taxonomy 的所有实例
     * @return CustomTaxonomy[]
     */
    public static function all() {
        $cls = get_called_class();
        return array_map(function($term) use ($cls) {
            return new $cls($term);
        }, get_terms($cls::$taxonomy, array(
            'hide_empty' => false,
        )));
    }


    /**
     * 获取当前分类法上面的所有匹配的 post
     * @param string $post_type 指定的 post 类名或者 post_type 名称
     * @return array 如果指定了 $post_type，返回一个对应的对象列表
     *      如果没有指定 $post_type，根据搜索到的 $post_type 返回一个关联所有分类的对象列表
     */
    public function posts($post_type=null) {

        $tax = get_taxonomy(static::$taxonomy);

        // CustomPost 子类分析
        $classes = array();
        foreach(get_declared_classes() as $cls) {
            // 找到所有 CustomPost 的子类
            if(is_subclass_of($cls, 'CustomPost')) {
                // 校验指定的 $post_type 是否与当前 taxonomy 关联
                if(!in_array($cls::$post_type, $tax->object_type)) continue;
                // 如果指定了 $post_type，跳过所有不匹配的部分
                if($post_type && $post_type != $cls &&
                    $post_type != $cls::$post_type) continue;

                $classes []= $cls;
            }
        }

        // 如果指定了 $post_type，校验完整性
        assert(
            !$post_type || sizeof($classes) === 1,
            __('Specified CustomPost not found.', WCP_DOMAIN)
        );

        // 逐个 post_type 进行查询
        $result = array();

        foreach($classes as $cls) {
            $result[$cls::$post_type] = array_map(function($post) use ($cls) {
                return new $cls($post);
            }, get_posts(array(
                'posts_per_page' => -1,
                'post_type' => $cls::$post_type,
                'tax_query' => array(
                    array(
                        'taxonomy' => static::$taxonomy,
                        'field' => 'id',
                        'terms' => $this->term->term_id,
                    )
                )
            )));
        }

        return $post_type ? $result[$classes[0]::$post_type] : $result;
    }

};


class CustomUserType {

    static $role;
    static $display_name;
    static $capabilities = array('read');

    public $user;

    function __construct($user) {
        if($user instanceof WP_User) {
            $this->user = $user;
        } else {
            $this->user = new WP_User(intval($user));
        }
    }

    // 执行动态属性的读写为 user_meta 的读写
    public function __get($key) {
        return get_user_meta($this->user->ID, $key, true);
    }

    // 执行动态属性的读写为 user_meta 的读写
    public function __set($key, $val) {
        update_user_meta($this->user->ID, $key, $val);
    }

    function __toString() {
        return strval($this->user->display_name);
    }

    static function init() {
        $class = get_called_class();
        add_action('init', function() use ($class) {
            if(WP_DEBUG) {
                // 调试模式下总是刷新角色
                remove_role($class::$role);
            }
            if(!get_role($class::$role)) {
                add_role(
                    $class::$role,
                    $class::$display_name,
                    $class::$capabilities
                );
            }
        });
    }

}


class CustomP2PType {

    // 配置属性，应在子类重写

    static $p2p_type = '';
    static $from_type = 'post';  // 'post' | 'user' | Custom Post Type
    static $from_class = '';
    static $from_title = 'To objects';
    static $to_type = 'post';  // 'post' | 'user' | Custom Post Type
    static $to_class = '';
    static $to_title = 'From objects';
    static $cardinality = 'many-to-many';  // 连接对应模式
    static $reciprocal = false;  // 是否对等关系（无向边）
    static $duplicate_connections = false;  // 是否支持重边
    static $fields = array();
    static $admin_box = array(
        'show' => 'any',  // any | from | to
        'context' => '',  // side | advanced
    );

    // 私有属性

    public $p2p_id;

    public $from;
    public $to;

    /**
     * 根据获取的 p2p 关联对象构造自定义
     * @param $object
     */
    function __construct($object) {
        if(is_numeric($object) || is_string($object)) {
            $this->p2p_id = intval($object);
        } else {
            assert(
                $object->p2p_id,
                __('The given object is not a valid p2p object.', WCP_DOMAIN)
            );
            $this->p2p_id = $object->p2p_id;
        }
        $conn = p2p_get_connection($this->p2p_id);
        $this->from = new static::$from_class(intval($conn->p2p_from));
        $this->to = new static::$to_class(intval($conn->p2p_to));
    }

    // 执行动态属性的读写为 p2p_meta 的读写
    function __get($key) {
        return p2p_get_meta($this->p2p_id, $key, true);
    }

    // 执行动态属性的读写为 p2p_meta 的读写
    function __set($key, $val) {
        p2p_update_meta($this->p2p_id, $key, $val);
    }

    // Register the type
    static function init() {

        $class = get_called_class();

        add_action('p2p_init', function() use ($class) {

            p2p_register_connection_type(array(
                'name' => $class::$p2p_type,
                'from' => $class::$from_type,
                'to' => $class::$to_type,
                'cardinality' => $class::$cardinality,
                'reciprocal' => $class::$reciprocal,
                'duplicate_connections' => $class::$duplicate_connections,
                'title' => array(
                    'from' => $class::$from_title,
                    'to' => $class::$to_title,
                ),
                'fields' => $class::$fields,
                'admin_box' => $class::$admin_box,
            ));

        });

    }

    /**
     * Extract an object from CustomPost to WP_Post
     * or from CustomUserType to WP_User
     * @param $obj WP_Post|WP_User|CustomPost|CustomUserType
     * @return WP_Post|WP_User
     */
    static function extract($obj) {
        if($obj instanceof CustomPost) return $obj->post;
        if($obj instanceof CustomUserType) return $obj->user;
        return $obj;
    }

    /**
     * Get the connection object from the from and to object.
     * If no connection is established, return false
     * @param $from
     * @param $to
     * @return bool|CustomP2PType
     */
    static function get($from, $to) {
        $class = get_called_class();
        $p2p_id = static::getType()->get_p2p_id(
            static::extract($from),
            static::extract($to)
        );
        return $p2p_id ? new $class(intval($p2p_id)) : false;
    }

    /**
     * @return bool|object
     */
    static function getType() {
        return p2p_type(static::$p2p_type);
    }

    static function connect($from, $to, $data=array()) {
        static::getType()->connect(
            static::extract($from),
            static::extract($to),
            $data
        );
    }

    static function disconnect($from, $to) {
        static::getType()->disconnect(
            static::extract($from),
            static::extract($to)
        );
    }

    function delete() {
        static::disconnect($this->from, $this->to);
    }

}



// ---------------------------------------------------------------


//// 调用范例，可写在 functions.php 中，或者另开文件
//class Customer extends CustomPost {
//    static $post_type = 'customer';
//    static $post_type_name = '客户';
//    static $menu_icon = 'dashicons-welcome-learn-more';
//};
//Customer::init();

// 提供方便的接口
class Page extends CustomPost {
    static $post_type = 'page';
};


