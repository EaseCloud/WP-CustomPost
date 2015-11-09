WP-CustomPost
=============

[逸云科技](http://www.easecloud.cn)内部创建的 CustomPost 插件，提供开发者友好的 CustomPost 类操作。

作者：[Alfred Huang/呆滞的慢板](https://www.huangwenchao.com.cn)

## Tutorial

### 1. Add CustomPost class wrapping for an existing post type.

First, extending a wrapper class for an existing post type as below.

You only need to override the `$post_type` static field to the current post type.

```php
class Page extends CustomPost {
    static $post_type = 'page';
}
```

Now, you can easily initialize a `Page` instance, and do the action on it:

```php
// Now we have a page with id=3 and slug='about'.
$page_about = new Page('about');

// call $instance->post to get the WP_Post object.
assert($page_about->post->ID === 3);

// set and get the post_meta.
$page_about->view_count = 5;
assert(
    get_post_meta($page_about->post->ID, 'view_count', true) == 
    $page->about->view_count
);

// get the author as a WP_User
$author = $page_about->getAuthor();
```

So, for an exist post_type, extending such a wrapper class of `CustomPost` can
enable many convenient functional on that object.

All classes extends `CustomPost` inherit all these methods, for more about the
available methods, see the full documentation.

## Documentation

### 1. CustomPost

### 2. CustomTaxonomy

### 3. CustomUserType

### 4. CustomP2PType
