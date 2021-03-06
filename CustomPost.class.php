<?php

/**
 * 自定义类，派生使用，派生时需要写入 static 参数进行配置。
 * 然后需要调用子类的 init 方法。
 */
class CustomPost
{

    // 配置选项（继承时必须填入这些属性）
    public static $post_type = 'post';
    public static $post_type_name;
    public static $post_type_name_plural;
    public static $post_type_description = '';
    public static $post_type_supports =
        array('title', 'thumbnail', 'excerpt', 'editor', 'comments', 'author');
    public static $menu_icon = 'dashicons-admin-post';
    public static $capabilities = array();

    public static $builtin_fields = array(
        'post_author',
        'post_date',
        'post_date_gmt',
        'post_content',
        'post_content_filtered',
        'post_title',
        'post_excerpt',
        'post_status',
        'post_type',
        'comment_status',
        'post_password',
        'post_name',
        'to_ping',
        'pinged',
        'post_modified',
        'post_modified_gmt',
        'post_parent',
        'menu_order',
        'post_mime_type',
        'guid',
        'text_input',
        'meta_input',
    );

    // 实有成员

    public $post;

    /**
     * 可以定义内容编辑文章的界面内容出现定义的 Meta Box
     * @var array
     * @ref ref: https://developer.wordpress.org/reference/functions/add_meta_box/
     */
    public static $meta_boxes = array(
//        'the-meta-box-id' => array(
//            'title' => 'the-meta-box-title',
//            'callback' => 'function', // callback to inject the metabox content
//            'screen' => null, // See the @ref
//            'context' => 'advanced', // normal | side | advanced
//            'priority' => 'default', // default | high | low
//            'callback_args' => null, // See the @ref
//            'callback_submit' => 'callback',  // callback on admin save post
//            // See hook save_post_$post_type
//            // https://developer.wordpress.org/reference/hooks/save_post/
//            //      do_action('save_post', int $post_id, WP_Post $post, bool $update)
//        ),
    );

    /** 构造函数
     * @param $post
     * @param $silence bool 是否不提示错误
     */
    function __construct($post, $silence = false)
    {

        // 构造 $post 对象
        if ($post instanceof WP_Post) {
            // 直接使用 post 对象构造的情况
            $this->post = $post;
        } elseif (!$post) {
            $this->post = null;
        } else {
            // 使用 slug | post_ID 构造的情况
            $this->post = get_page_by_path($post, OBJECT, static::$post_type)
                ?: get_post($post);
        }

        // 校验 $post 的类型
        if (!$silence && (!$this->post || $this->post->post_type !== static::$post_type)) {
            wp_die(
                __('Constructing post type in not correct, should be of type: ', WCP_DOMAIN)
                . static::$post_type
            );
        }

    }

    function __toString()
    {
        return strval($this->post->post_title);
    }

    // 执行动态属性的读写为 post_meta 的读写
    function __get($key)
    {
        // 破坏性更新，可能会导致旧版接口不兼容
        return @get_field($key, $this->post->ID) ?: @$this->post->$key;
//        return isset($this->post->$key) ? $this->post->$key :
//            get_field($key, $this->post->ID);
    }

    // 执行动态属性的读写为 post_meta 的读写
    function __set($key, $val)
    {
        if (in_array($key, static::$builtin_fields)) {
            wp_update_post(array(
                'ID' => $this->ID,
                $key => $val,
            ));
            return;
        }

        update_post_meta($this->ID, $key, $val);

        // 更新类型
        $type = static::getFieldType($key);
        if ($type) update_post_meta($this->ID, "_$key", $type);
    }

    /**
     * 获取 ACF 字段的类型编码
     * @param $field_name
     * @return string 字段
     */
    static function getFieldType($field_name)
    {
        foreach (ACFFieldGroup::getListFromPostType(static::$post_type) as $acf) {
            $field = $acf->getField($field_name);
            if ($field) {
                return $field->key;
            }
        }
        return false;
    }

    /**
     * 插入一片此类型的文章
     * @param string $title
     * @param string $slug
     * @param string $content
     * @param array $meta_fields
     * @param string $status
     * @param array $postarr
     * @return CustomPost 返回插入成功之后的对象
     */
    static function insert($title, $slug, $content, $meta_fields, $status = 'publish', $postarr = array())
    {
        $post_id = wp_insert_post(array_merge(array(
            'post_title' => $title,
            'post_name' => $slug,
            'post_content' => $content,
            'post_type' => static::$post_type,
            'post_status' => $status,
        ), $postarr));
        if (is_wp_error($post_id)) wp_die($post_id);
        $result = new static($post_id);
        foreach ($meta_fields as $key => $val) {
            $result->$key = $val;
        }
        // 初始化 acf 的字段引用绑定
        foreach (get_posts(array('post_type' => 'acf', 'posts_per_page' => -1)) as $acf) {
            $meta = get_post_meta($acf->ID);
            $rule = unserialize($meta['rule'][0]);
            if ($rule['param'] == 'post_type' &&
                $rule['operator'] == '==' &&
                $rule['value'] == static::$post_type
            ) {
                foreach ($meta as $key => $field) {
                    if (substr($key, 0, 6) == 'field_') {
                        $field = unserialize($field[0]);
                        update_post_meta($post_id, '_' . $field['name'], $key);
                    }
                }
            }
        }
        return $result;
    }

    /**
     * 获取当前 Post 的作者 User 对象
     */
    function getAuthor()
    {
        return new WP_User($this->post->post_author);
    }

    // 获取当前商品的特色图像，如果没有设置返回默认图。
    function getThumbnailUrl()
    {
        $img_id = get_post_thumbnail_id($this->post->ID);
        if (!$img_id) return get_template_directory_uri() . '/images/thumbnail.png';
        $img = wp_get_attachment_image_src($img_id, 'full');
        return $img[0];
    }

    static function getArchiveUrl()
    {
        return get_post_type_archive_link(static::$post_type);
    }

    // 初始化脚本，完成 post_type 注册等工作，派生该类之后，如果需要使用必须手动先执行一次
    static function init()
    {

        // 需要获取调用的子类
        $class = get_called_class();

        // 需要调入闭包的变量
        add_action('init', function () use ($class) {

            register_post_type($class::$post_type, array(
                'label' => $class::$post_type_name,
                'description' => $class::$post_type_description,
                'labels' => array(
                    'name' => $class::$post_type_name,
                    'singular_name' => $class::$post_type_name,
                    'menu_name' => $class::$post_type_name,
                    'parent_item_colon' => __('Parent ', WCP_DOMAIN) . $class::$post_type_name,
                    'all_items' => __('All ', WCP_DOMAIN) . $class::$post_type_name_plural,
                    'view_item' => __('View ', WCP_DOMAIN),
                    'add_new_item' => __('Add ', WCP_DOMAIN) . $class::$post_type_name,
                    'add_new' => __('Add ', WCP_DOMAIN) . $class::$post_type_name,
                    'edit_item' => __('Edit ', WCP_DOMAIN) . $class::$post_type_name,
                    'update_item' => __('Update', WCP_DOMAIN),
                    'search_items' => __('Search ', WCP_DOMAIN) . $class::$post_type_name,
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

        // register the post_type meta_boxes
        foreach ($class::$meta_boxes as $meta_box_id => $args) {

            add_action(
                'add_meta_boxes_' . $class::$post_type,
                function ($post) use ($class, $meta_box_id, $args) {
                    add_meta_box(
                        $meta_box_id,
                        @$args['title'] ?: __('Meta box', WCP_DOMAIN),
                        @$args['callback'] ?: null,
                        @$args['screen'] ?: null,
                        @$args['context'] ?: 'advanced',
                        @$args['priority'] ?: 'default',
                        @$args['callback_args'] ?: null
                    );

                }
            );

            // Todo: 尚未精确判断，只有后台发送保存指令时才执行
            // dealing with metabox submit
            if (isset($args['callback_submit'])) {
                add_action('save_post_' . $class::$post_type, $args['callback_submit'], 18, 3);
            }
        }

    }


    /**
     * 获取当前 post 对象对应的所有 CustomTaxonomy 关联的对象
     * @param string $taxonomy 指定的 Taxonomy 别名或者类名
     * @return self[] 如果指定了 $taxonomy，返回一个 CustomTaxonomy 的纯数组
     * 如果没有指定，根据 $taxonomy 的别名分别指定数据返回
     */
    function terms($taxonomy = null)
    {

        // CustomTaxonomy 子类分析
        $classes = array();
        foreach (get_declared_classes() as $cls) {
            // 找到所有 CustomTaxonomy 的子类
            if (is_subclass_of($cls, 'CustomTaxonomy')) {
                $tax = get_taxonomy($cls::$taxonomy);
                // 校验指定的 $post_type 是否与当前 taxonomy 关联
                if (!in_array(static::$post_type, $tax->object_type)) continue;
                // 如果指定了 $post_type，跳过所有不匹配的部分
                if ($taxonomy && $taxonomy != $cls &&
                    $taxonomy != $cls::$taxonomy
                ) continue;

                $classes [] = $cls;
            }
        }

        // 如果指定了 $taxonomy，校验完整性
        if ($taxonomy && sizeof($taxonomy) !== 1) {
            wp_die(
                __('Specified CustomTaxonomy not found.', WCP_DOMAIN)
            );
        }

        // 逐个 post_type 进行查询
        $result = array();

        foreach ($classes as $cls) {
            $result[$cls::$taxonomy] = array_map(function ($term) use ($cls) {
                return new $cls($term);
            }, get_the_terms($this->post, $cls::$taxonomy)
            );
        }

        return $taxonomy ? $result[$classes[0]::$taxonomy] : $result;

    }

    /**
     * 返回当前 post 对象是否具备某一个 term
     * @param $term
     * @return mixed
     */
    function hasTerm($term, $tax)
    {
        // 兼容各种输入类型
        $term = @$term->term_id ?: @$term->term->term_id ?: $term;
        foreach ($this->terms($tax) as $_term) {
            $_term = @$_term->term_id ?: @$_term->term->term_id ?: $_term;
            if ($term == $_term) return true;
        }
        return false;
    }

    /**
     * 获取当前文章的超链接
     */
    function getPermalink()
    {
        return get_the_permalink($this->post);
    }

    /**
     * 列表查询
     * @param array $args
     * @param bool|false $raw
     * @return self[]|WP_Post[]
     * @link: https://wordpress.org/search/get_posts
     */
    static function query($args = array(), $raw = false)
    {
        $args = array_merge($args, array(
            'post_type' => static::$post_type
        ));
        $posts = get_posts($args);
        if ($raw) {
            return $posts;
        } else {
            $result = array();
            foreach ($posts as $p) {
                $result [] = new static($p);
            }
            return $result;
        }
    }

    function delete()
    {
        wp_delete_post($this->ID);
    }

}


/**
 * Class CustomTaxonomy
 * 自定义分类法
 */
class CustomTaxonomy
{

    // 配置选项（继承时必须填入这些属性）
    public static $taxonomy;  // tax 的别名
    public static $taxonomy_name;  // tax 的显示名称
    public static $taxonomy_name_plural;  // tax 的显示名称（复数）
    public static $post_types = array();  // tax 匹配的 post type
    public static $capabilities = array();
    public static $show_ui = true;
    public static $show_admin_column = true;
    public static $hierarchical = true;  // 分类是否支持层级

    // 实有成员
    public $term;

    /** 构造函数
     * @param $term
     */
    function __construct($term)
    {

        // 构造 $term 对象
        if (($term instanceof stdClass || $term instanceof WP_Term) && $term->term_id) {
            // 直接使用 term 对象构造的情况
            $this->term = $term;
        } elseif (is_int($term)) {
            // 使用 term_ID 构造的情况
            $this->term = get_term($term, static::$taxonomy);
        } else {
            // 使用 slug 构造
            $this->term = get_term_by('slug', $term, static::$taxonomy);
        }

        if (!$this->term || $this->term->taxonomy !== static::$taxonomy) {
            die(
                __('Constructing term type is not correct, should be of [', WCP_DOMAIN)
                . static::$taxonomy . __('] taxonomy.', WCP_DOMAIN)
            );
        }

    }

    function __toString()
    {
        return strval($this->term->name);
    }

    /**
     * 获取当前分类的超链接
     */
    function getPermalink()
    {
        return get_category_link($this->term->term_id);
    }

    /**
     * 获取当前分类的子分类
     * @return CustomTaxonomy[]
     */
    function children()
    {
        $terms = get_terms(static::$taxonomy, array(
            'parent' => $this->term->term_id,
            'hide_empty' => false
        ));
        $result = array();
        foreach ($terms as $term) {
            $result [] = new static($term);
        }
        return $result;
    }

    /**
     * 获取当前分类的父分类
     * @return CustomTaxonomy|null
     */
    function parent()
    {
        if ($this->term->parent) {
            return new static($this->term->parent);
        }
        return null;
    }

    /**
     * 获取当前分类的根节点分类
     * @return CustomTaxonomy|null
     */
    function getRootNode()
    {
        $tax = $this;
        while ($tax->term->parent) {
            $tax = $tax->parent();
        }
        return $tax;
    }

    /**
     * 初始化脚本，完成 taxonomy 注册等工作，派生该类之后，如果需要使用必须手动先执行一次
     */
    static function init()
    {

        // 需要获取调用的子类
        $class = get_called_class();

        // 需要调入闭包的变量
        add_action('init', function () use ($class) {

            register_taxonomy($class::$taxonomy, $class::$post_types, array(
                'hierarchical' => $class::$hierarchical,
                'show_ui' => $class::$show_ui,
                'show_admin_column' => $class::$show_admin_column,
                'query_var' => true,
                'rewrite' => array('slug' => $class::$taxonomy),
                'capabilities' => $class::$capabilities,
                'labels' => array(
                    'name' => $class::$taxonomy_name,
                    'singular_name' => $class::$taxonomy_name,
                    'search_items' => __('Search ', WCP_DOMAIN) . $class::$taxonomy_name,
                    'all_items' => __('All ', WCP_DOMAIN) . $class::$taxonomy_name_plural,
                    'parent_item' => __('Parent ', WCP_DOMAIN) . $class::$taxonomy_name,
                    'parent_item_colon' => __('Parent ', WCP_DOMAIN) . $class::$taxonomy_name,
                    'edit_item' => __('Edit ', WCP_DOMAIN) . $class::$taxonomy_name,
                    'update_item' => __('Update', WCP_DOMAIN),
                    'add_new_item' => __('Add ', WCP_DOMAIN) . $class::$taxonomy_name,
                    'new_item_name' => __('Add ', WCP_DOMAIN) . $class::$taxonomy_name,
                    'menu_name' => $class::$taxonomy_name,
                ),
            ));

        }, 10, 0);

    }


    /**
     * 返回当前定义的 Taxonomy 的所有实例
     * @return CustomTaxonomy[]
     */
    static function all()
    {
        $cls = get_called_class();
        return array_map(function ($term) use ($cls) {
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
    function posts($post_type = null)
    {

        $tax = get_taxonomy(static::$taxonomy);

        // CustomPost 子类分析
        $classes = array();
        foreach (get_declared_classes() as $cls) {
            // 找到所有 CustomPost 的子类
            if (is_subclass_of($cls, 'CustomPost')) {
                // 校验指定的 $post_type 是否与当前 taxonomy 关联
                if (!in_array($cls::$post_type, $tax->object_type)) continue;
                // 如果指定了 $post_type，跳过所有不匹配的部分
                if ($post_type && $post_type != $cls &&
                    $post_type != $cls::$post_type
                ) continue;

                $classes [] = $cls;
            }
        }

        // 如果指定了 $post_type，校验完整性
        if ($post_type && sizeof($classes !== 1)) {
            wp_die(__('Specified CustomPost not found.', WCP_DOMAIN));
        }

        // 逐个 post_type 进行查询
        $result = array();

        foreach ($classes as $cls) {
            $result[$cls::$post_type] = array_map(function ($post) use ($cls) {
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

}


/**
 * Class CustomUserType
 * 自定义用户类型（依赖 role 角色）
 */
class CustomUserType
{

    static $role = null;  // Accept role_name or null
    static $display_name;
    static $capabilities = array('read');

    public $user;

    /**
     * @param $user WP_User|int 构造的用户对象或者用户 id
     * @param bool|false $silence 是否不提示错误
     */
    function __construct($user, $silence = false)
    {

        if ($user instanceof WP_User) {
            $this->user = $user;
        } elseif (is_numeric($user) || is_string($user)) {
            $this->user = get_user_by('id', intval($user))
                ?: get_user_by('login', $user)
                    ?: get_user_by('slug', $user)
                        ?: get_user_by('email', $user);
        } else {
            wp_die(__('User construction error!', WCP_DOMAIN));
        }

        if (!$silence && static::$role && static::$role !== $this->user->roles[0]) {
            wp_die(
                __('Construct user role is not correct, should be:', WCP_DOMAIN)
                . static::$role
            );
        }

    }

    // 执行动态属性的读写为 user_meta 的读写
    function __get($key)
    {
        return isset($this->user->$key) ? $this->user->$key :
            get_field($key, "user_{$this->user->ID}");
    }

    // 执行动态属性的读写为 user_meta 的读写
    function __set($key, $val)
    {
        update_user_meta($this->user->ID, $key, $val);

        // 更新类型
        $type = static::getFieldType($key);
        if ($type) update_user_meta($this->ID, "_$key", $type);
    }

    /**
     * 获取 ACF 字段的类型编码
     * @param $field_name
     * @return string 字段
     */
    static function getFieldType($field_name)
    {
        foreach (ACFFieldGroup::getListFromRole(static::$role) as $acf) {
            $field = $acf->getField($field_name);
            if ($field) {
                return $field->key;
            }
        }
        return false;
    }

    function __toString()
    {
        return strval($this->user->display_name);
    }

    static function init()
    {
        $class = get_called_class();
        add_action('init', function () use ($class) {
            if (WP_DEBUG) {
                // 调试模式下总是刷新角色
                remove_role($class::$role);
            }
            if (!get_role($class::$role)) {
                add_role(
                    $class::$role,
                    $class::$display_name,
                    $class::$capabilities
                );
            }
        });
        /**
         * 微信头像
         */
        add_filter('get_avatar', function ($avatar, $user_id, $size) {
            $src = @get_user_meta($user_id, 'avatar', true);
            return $src ? "<img src=\"$src\" width=\"$size\" />" : $avatar;
        }, 18, 3);
    }

    /**
     * 获取当前用户的超链接
     */
    function getPermalink()
    {
        return get_author_posts_url($this->user->ID);
    }

    /**
     * Create a user of the current specified role
     * @param string $user_login
     * @param string $user_pass
     * @param string $user_email
     * @param array $extra : Allowed keys :
     *      user_nicename | user_url | display_name | nickname |
     *      first_name | last_name | description | rich_editing |
     *      user_registered
     * @return CustomUserType|bool
     * @link https://codex.wordpress.org/Function_Reference/wp_insert_user
     */
    static function create($user_login, $user_pass = null,
                           $user_email = null, $extra = array())
    {

        // 缺省自动生成 email 和密码
        if (!$user_email) {
            preg_match('/^(?:https?:\/\/)?([^\/]+)/', home_url(), $match);
            $host = @$match[1] ?: 'wordpress.org';
            $user_email = "{$user_login}@{$host}";
        }
        $user_pass = $user_pass ?: wp_generate_password();
        $role = static::$role;
        $user = wp_insert_user(array_merge(compact(
            'user_pass', 'user_login', 'user_email', 'role'
        ), $extra));

        // 如果用户创建成功，返回创建的 CustomUserType 对象
        if (!is_wp_error($user)) {
            return new static($user);
        } else {
            wp_die($user);
            return false;
        }
    }

    /**
     * 查询出一个用户对象的列表
     * @param array $args
     * @param bool|false $raw
     * @return self[]
     * @link: https://wordpress.org/search/get_users
     */
    static function query($args = array(), $raw = false)
    {
        $args = array_merge($args, array(
            'role' => static::$role
        ));
        $users = get_users($args);
        if ($raw) {
            return $users;
        } else {
            $result = array();
            foreach ($users as $u) {
                $result [] = new static($u);
            }
            return $result;
        }
    }

    function delete()
    {
        require_once(ABSPATH . 'wp-admin/includes/user.php');
        wp_delete_user($this->user->ID);
    }

    /**
     * 获取当前登录的会员用户，没有的话返回 false
     * @return self|false
     */
    static function get_current()
    {

        // 当前登录的用户
        $user = wp_get_current_user();

        // 如果没有登录或者角色不是当前的类型，都返回 false
        if ($user && (!static::$role || in_array(@static::$role, $user->roles))) {
            // 返回当前的用户对象
            return new static($user);
        } else {
            return false;
        }

    }


    /**
     * 获取当前类型的会员用户，没有的话跳转到登录页面
     * @param string|bool $login_url 另行指定的登录页面
     * @return self
     */
    static function require_login($login_url = false)
    {
        $user = static::get_current();
        if (!$user) {
            ob_clean();
            wp_redirect($login_url ?: wp_login_url($_SERVER['REQUEST_URI']));
            exit;
        }
        return $user;
    }

    /**
     * 登录当前的用户
     * @ref http://wordpress.stackexchange.com/a/128445/85201
     */
    function login()
    {
        wp_clear_auth_cookie();
        wp_set_current_user($this->user->ID);
        wp_set_auth_cookie($this->user->ID);
    }

    /**
     * 设置当前用户的密码
     * @param $new_password
     */
    function change_password($new_password)
    {
        reset_password($this->user, $new_password);
    }

    /**
     * Make user bind with wechat.
     * 1. If not logined, load wechat info and find matching user, then login.
     * 2. If no matching user, register one with wechat info.
     * 3. If is logined at the beginning, and no wechat bound, bound with the info.
     * 4. If is logined at the beginning and wechat bounded, ignore.
     * @return self|false the user authenticated
     */
    static function wechat_load_user()
    {
        if (!class_exists('WP_Wechat')) return false;

        $wechat = new WP_Wechat();
        if (!$wechat->is_wechat()) return false;

        $me = static::get_current();
        // Case 4
        if ($me && $me->openid) return $me;

        $ticket = @$_GET['ticket'];
        // 没有 ticket 跳转获取用户信息接口
        if (!$ticket) {
            exit("<script>location = '{$wechat->auth_server}/auth/{$wechat->app_id}/'</script>");
//                . '?redirect_uri=' .  home_url($_SERVER['REQUEST_URI']));
//            exit;
        }

        // 获取用户信息
        $userinfo = @json_decode(file_get_contents("{$wechat->auth_server}/ticket/$ticket/"));

        if (!$userinfo) wp_die('获取微信用户信息失败');

        if ($me) {
            // Case 3
            foreach ($userinfo as $key => $value) {
                $me->$key = $value;
            }
            return $me;
        }
        $users = static::query(array(
            'meta_key' => 'openid',
            'meta_value' => $userinfo->openid,
        ));
        $me = @$users[0];
        // Case 2
        if (!$me) {
            $me = static::create(
                @$userinfo->openid, null, null,
                array(
                    'nickname' => $userinfo->nickname,
                    'first_name' => $userinfo->nickname,
                    'user_nicename' => $userinfo->nickname,
                    'display_name' => $userinfo->nickname,
                )
            );
            foreach ($userinfo as $key => $value) {
                $me->$key = $value;
            }
        }
        $me->login();
        return $me;
    }
}


/**
 * Class CustomP2PType
 * 自定义的 Posts 2 Posts 类型
 */
class CustomP2PType
{

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
    static $fields = array();  // array(key => val) val: (str)title or
    // array(title=>?, type=>?, values=>?, default=>?, default_cb=>(callback))
    // @link: https://github.com/scribu/wp-posts-to-posts/wiki/Connection-metadata
    static $admin_box = array(
        'show' => 'any',  // any | from | to
        'context' => 'side',  // side | advanced
    );

    // 私有属性

    public $p2p_id;

    public $from;
    public $to;

    /**
     * 根据获取的 p2p 关联对象构造自定义
     * @param $object
     */
    function __construct($object)
    {
        if (is_numeric($object) || is_string($object)) {
            $this->p2p_id = intval($object);
        } elseif (@$object->p2p_id) {
            $this->p2p_id = $object->p2p_id;
        } else {
            wp_die(
                __('The given object is not a valid p2p object.', WCP_DOMAIN)
            );
        }
        $conn = p2p_get_connection($this->p2p_id);
        $this->from = new static::$from_class(intval($conn->p2p_from));
        $this->to = new static::$to_class(intval($conn->p2p_to));
    }

    // 执行动态属性的读写为 p2p_meta 的读写
    function __get($key)
    {
        return p2p_get_meta($this->p2p_id, $key, true);
    }

    // 执行动态属性的读写为 p2p_meta 的读写
    function __set($key, $val)
    {
        p2p_update_meta($this->p2p_id, $key, $val);
    }

    // Register the type
    static function init()
    {

        if (!function_exists('p2p_register_connection_type')) {
            echo 'Posts 2 Posts plugin is required.';
            // wp_die(__('Posts 2 Posts plugin is required.'));
            return;
        }

        $class = get_called_class();

        add_action('p2p_init', function () use ($class) {

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
    static function extract($obj)
    {
        if ($obj instanceof CustomPost) return $obj->post;
        if ($obj instanceof CustomUserType) return $obj->user;
        return $obj;
    }

    /**
     * Get the connection object from the from and to object.
     * If no connection is established, return false
     * @param $from
     * @param $to
     * @return bool|CustomP2PType
     */
    static function get($from, $to)
    {
        $p2p_id = static::getType()->get_p2p_id(
            static::extract($from),
            static::extract($to)
        );
        return $p2p_id ? new static(intval($p2p_id)) : false;
    }

    /**
     * @return bool|object
     */
    static function getType()
    {
        return p2p_type(static::$p2p_type);
    }

    /**
     * 连接两个对象，并且返回构造的 CustomP2P 对象
     * @param $from
     * @param $to
     * @param array $data
     * @return bool|self
     */
    static function connect($from, $to, $data = array())
    {
        $p2p_id = static::getType()->connect(
            static::extract($from),
            static::extract($to),
            $data
        );
        return is_wp_error($p2p_id) ? false : new static($p2p_id);
    }

    static function disconnect($from, $to)
    {
        static::getType()->disconnect(
            static::extract($from),
            static::extract($to)
        );
    }

    function unlink()
    {
        p2p_delete_connection($this->p2p_id);
    }

    /**
     * Return a list of connection objects from a related object.
     * @param $item
     * @param $direction
     * @return self[]: resulting CustomP2PType object list.
     */
    static function getList($item, $direction = 'auto', $connected_meta = array())
    {
        if (!in_array($direction, array('from', 'to'))) {
            $to_class = static::$to_class;
            $direction = $item instanceof $to_class ? 'to' : 'from';
        }
        $item_class = $direction == 'from' ?
            static::$to_class : static::$from_class;
        $items = $item_class::query(array(
            'connected_type' => static::$p2p_type,
            'connected_items' => static::extract($item),
            'connected_direction' => $direction,
            'connected_meta' => $connected_meta,
            'posts_per_page' => -1,
        ));
        $result = array();
        foreach ($items as $item) {
            $result [] = new static(static::extract($item)->p2p_id);
        }
        return $result;
    }

    function delete()
    {
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

/**
 * Class Page
 */
class Page extends CustomPost
{
    static $post_type = 'page';

    /**
     * 根据 slug > title > ID 的优先级动态生成页面的链接
     * @param $key
     * @return false|string
     */
    static function url($key)
    {
        $page = get_page_by_path($key) ?: get_page_by_title($key);
        return @get_the_permalink($page ?: $key);
    }
}

/**
 * Class Category
 */
class Category extends CustomTaxonomy
{
    static $taxonomy = 'category';
}

/**
 * Class PostTag
 */
class PostTag extends CustomTaxonomy
{
    static $taxonomy = 'post_tag';
}

/**
 * Class ACFFieldGroup
 */
class ACFFieldGroup extends CustomPost
{
    static $post_type = 'acf-field-group';
    public $fields = null;
    public $__fields = array();

    /**
     * 获取定义的 ACFField 对象
     * @param $field_name string|null 如果为空，直接返回所有，否则返回指定的对象
     * @return ACFField[]|ACFField|null
     */
    function getField($field_name = null)
    {
        if (!$this->fields) {
            $this->fields = ACFField::query(array(
                'posts_per_page' => -1,
                'post_parent' => $this->post->ID,
            ));
            foreach ($this->fields as &$field) {
                $this->__fields[$field->name] = &$field;
            }
        }
        return $field_name ? @$this->__fields[$field_name] : $this->fields;
    }

    /**
     * 返回绑定到某个 role 的 ACF 字段组列表
     * @param $post_type
     * @return ACFFieldGroup[]
     */
    static function getListFromPostType($post_type)
    {
        $result = array();
        foreach (static::query(array('posts_per_page' => -1)) as &$acf) {
            $data = @unserialize($acf->post->post_content);
            if (!$data && @$data['location']) continue;
            $is_match = false;
            foreach ($data['location'] as &$rule) {
                if ($rule[0]['param'] == 'post_type') {
                    if ($rule[0]['value'] == $post_type && $rule[0]['operator'] == '==') {
                        $is_match = true;
                        break;
                    }
                    if ($rule[0]['value'] == $post_type && $rule[0]['operator'] == '!=') {
                        $is_match = false;
                        break;
                    }
                }
            }
            if ($is_match) $result [] = &$acf;
        }
        return $result;
    }

    /**
     * 返回绑定到某个 role 的 ACF 字段组列表
     * @param $role
     * @return self[]
     */
    static function getListFromRole($role)
    {
        $result = array();
        foreach (static::query(array('posts_per_page' => -1)) as &$acf) {
            $data = @unserialize($acf->post->post_content);
            if (!$data && @$data['location']) continue;
            $is_match = false;
            foreach ($data['location'] as &$rule) {
                if ($rule[0]['param'] == 'user_role') {
                    if ($rule[0]['value'] == $role && $rule[0]['operator'] == '==') {
                        $is_match = true;
                        break;
                    }
                    if ($rule[0]['value'] == $role && $rule[0]['operator'] == '!=') {
                        $is_match = false;
                        break;
                    }
                }
            }
            if ($is_match) $result [] = &$acf;
        }
        return $result;
    }
}

/**
 * Class ACFField
 */
class ACFField extends CustomPost
{
    static $post_type = 'acf-field';
    public $fields = null;
    public $__fields = array();
    public $__content = array();
    public $title = '';  // 字段显示名称
    public $name = '';  // 字段关键字
    public $key = '';  // 字段内置编号

    function __construct($post, $silence = false)
    {
        parent::__construct($post, $silence);
        $this->__content = unserialize($this->post->post_content);
        $this->key = $this->post->post_name;
        $this->name = $this->post->post_excerpt;
        $this->title = $this->post->post_title;
    }

    /**
     * @param array $args
     * @param bool|false $raw
     * @return self[]|WP_Post[]
     */
    static function query($args = array(), $raw = false)
    {
        return parent::query($args, $raw);
    }

    /**
     * 获取字段的选项值，优先从字段配置中读取，支持的参数有：
     *
     * - choices: 选项
     * - type: 字段类型
     * - instructions: 字段说明
     * - required: 0/1 是否必填
     * - default_value: 默认值
     * - allow_null: 是否可空
     * - disabled: 是否禁用
     * - placeholder: 占位字符
     * - readonly: 是否只读
     * - multiple: 是否多选
     *
     * 还有其他诸如 ajax / conditional_logic / ui / wrapper 等不常用
     *
     * @param $key
     * @return mixed
     */
    function __get($key)
    {
        if (isset($this->__content[$key])) {
            return $this->__content[$key];
        }
        return parent::__get($key);
    }

    /**
     * 获取子字段 ACFField 对象
     * @param $field_name string|null 如果为空，直接返回所有，否则返回指定的对象
     * @return ACFField[]|ACFField|null
     */
    function getSubField($field_name = null)
    {
        if (!$this->fields) {
            $this->fields = ACFField::query(array(
                'posts_per_page' => -1,
                'post_parent' => $this->post->ID,
            ));
            foreach ($this->fields as &$field) {
                $this->__fields[$field->name] = &$field;
            }
        }
        return $field_name ? $this->__fields[$field_name] : $this->fields;
    }
}
