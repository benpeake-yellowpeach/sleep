<?php

namespace YPTheme;

use PHP_CodeSniffer\Util\Help;
use Timber\Timber;
use Twig\TwigFunction;
use DirectoryIterator;
use FilesystemIterator;
use RecursiveDirectoryIterator;
use WP_Query;

class TimberSetup
{
    public static function init()
    {
        self::include_acf_fields(); // Include ACF fields from blocks
        Timber::init();
        add_filter('timber/twig', [self::class, 'add_twig_functions']);
        add_action('after_setup_theme', [self::class, 'register_timber_blocks']);
        add_filter('timber/context', [self::class, 'add_global_timber_context']);
        add_filter('timber/post/classmap', [self::class, 'register_classmap']);
        add_filter('timber/loader/loader', [self::class, 'register_timber_blocks_path']);
    }

    public static function add_twig_functions($twig)
    {
        $functions = [
            'home_url',
            'get_the_title',
            'get_field',
            'is_home',
            'is_category',
            'is_single',
            'get_permalink',
            'get_option'
        ];

        foreach ($functions as $fn) {
            $twig->addFunction(new TwigFunction($fn, $fn));
        }

        $twig->addFunction(new TwigFunction('d', [Debugging::class, 'd']));
        $twig->addFunction(new TwigFunction('dd', [Debugging::class, 'dd']));

        $twig->addFunction(new TwigFunction('get_link_attr', [HelperFunctions::class, 'get_link_attr']));
        $twig->addFunction(new TwigFunction('get_breadcrumb', [HelperFunctions::class, 'get_breadcrumb']));
        $twig->addFunction(new TwigFunction('responsive_img', [Images::class, 'responsive_img']));

        return $twig;
    }

    public static function register_timber_blocks()
    {
        new \YPTheme\TimberAcfBlocks();
    }

    public static function add_global_timber_context($context)
    {
        $context['general_settings'] = \YPTheme\HelperFunctions::get_general_settings();
        return $context;
    }

    public static function register_timber_blocks_path($loader)
    {
        $loader->addPath(get_template_directory() . '/views/blocks', "blocks");
        return $loader;
    }

    public static function register_classmap($classmap)
    {
        $custom_classmap = [
            'faq' => \Timber\Extensions\FaqPostType::class,
            'testimonial' => \Timber\Extensions\TestimonialPostType::class
        ];

        return array_merge($classmap, $custom_classmap);
    }

    public static function include_acf_fields()
    {
        $blocks_path = get_template_directory() . '/views/blocks';

        if (!is_dir($blocks_path)) {
            return;
        }

        $directoryIterator = new \RecursiveDirectoryIterator($blocks_path);
        $iterator = new \RecursiveIteratorIterator($directoryIterator);

        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getFilename() === 'fields.php') {
                require_once $file->getPathname();
            }
        }
    }
}


class TimberAcfBlocks
{
    public function __construct()
    {
        if (is_callable('acf_register_block_type') && class_exists('Timber')) {
            add_action('init', [self::class, 'timber_block_init']);
        }
    }

    public static function timber_block_init()
    {
        $directories = self::timber_block_directory_getter();

        foreach ($directories as $dir) {
            if (!file_exists(locate_template($dir))) {
                return;
            }

            $template_directory = new DirectoryIterator(locate_template($dir));

            foreach ($template_directory as $template) {
                if ($template->isDot() || $template->isDir()) {
                    continue;
                }

                $file_parts = pathinfo($template->getFilename());
                if ('twig' !== $file_parts['extension']) {
                    continue;
                }

                $block_absolute_path = get_template_directory() . '/' . $dir;

                register_block_type($block_absolute_path, [
                    'render_callback' => [self::class, 'timber_blocks_callback'],
                    'attributes' => [
                        'style' => [
                            'type' => 'object',
                            'default' => [
                                'spacing' => [
                                    'padding' => [
                                        'top' => '80px',
                                        'bottom' => '80px',
                                    ]
                                ]
                            ]
                        ]
                    ]
                ]);
            }
        }
    }

    public static function timber_blocks_callback($block, $content = '', $is_preview = false, $post_id = 0)
    {
        $context = Timber::context();
        $slug = str_replace('acf/', '', $block['name']);

        $context['block'] = $block;
        $context['post_id'] = $post_id;
        $context['slug'] = $slug;
        $context['is_preview'] = $is_preview;
        $context['fields'] = get_fields();


        // Get posts archive data for posts archive block
        $context['posts'] = self::get_posts_archive($block);
        $context['testimonials'] = self::get_testimonials($block);
        $context['faqs'] = self::get_faqs($block);

        // Assign block spacing 
        $context['block_spacing'] = trim(
            self::generate_spacing_classes($block['style']['spacing']['padding']['top'] ?? '', 'padding-top') . ' ' .
                self::generate_spacing_classes($block['style']['spacing']['padding']['bottom'] ?? '', 'padding-bottom')
        );

        // Assign block color
        $backgroundColor = isset($block['backgroundColor']) ? $block['backgroundColor'] : 'bg-background-primary-bg-fill';

        if (str_starts_with($backgroundColor, 'dark-')) {
            $context['background_color'] = str_replace('dark-', '', $backgroundColor);
            $context['color_profile'] = 'dark-theme';
        } elseif (str_starts_with($backgroundColor, 'light-')) {
            $context['background_color'] = str_replace('light-', '', $backgroundColor);
            $context['color_profile'] = 'light-theme';
        } else {
            $context['background_color'] = $backgroundColor;
            $context['color_profile'] = 'dark';
        }

        $context = apply_filters('timber/acf-gutenberg-blocks-data', $context);
        Timber::render(self::timber_acf_path_render($slug, $is_preview), $context);
    }


    /**
     * Convert WordPress spacing presets to class names.
     */
    private static function generate_spacing_classes($spacing_value, $prefix)
    {
        if (strpos($spacing_value, 'var:preset|spacing|') === 0) {
            $preset_key = str_replace('var:preset|spacing|', '', $spacing_value);
            return $prefix . '-' . $preset_key;
        }
        return '';
    }

    /**
     * Custom pagination
     */
    private static function generate_pagination($query, $current_page)
    {
        $total_pages = $query->max_num_pages;
        if ($total_pages <= 1) {
            return null;
        }

        $visible_pages = [];

        if ($total_pages > 3) {
            if ($current_page == 1) {
                $visible_pages = [1, 2, 3];
            } elseif ($current_page == $total_pages) {
                $visible_pages = [$total_pages - 2, $total_pages - 1, $total_pages];
            } else {
                $visible_pages = [$current_page - 1, $current_page, $current_page + 1];
            }
        } else {
            $visible_pages = range(1, $total_pages);
        }

        return [
            'pages'     => array_map(function ($page) use ($current_page) {
                return [
                    'title'   => $page,
                    'link'    => esc_url(get_pagenum_link($page)),
                    'current' => $page == $current_page,
                ];
            }, $visible_pages),
            'prev'      => ($current_page > 1) ? ['link' => get_pagenum_link($current_page - 1)] : null,
            'next'      => ($current_page < $total_pages) ? ['link' => get_pagenum_link($current_page + 1)] : null,
            'show_pre'  => $visible_pages[0] > 1,
            'show_post' => $visible_pages[count($visible_pages) - 1] < $total_pages,
        ];
    }

    /**
     * Get posts helper
     */
    private static function get_related_posts($block, $block_name, $post_type, $field_name)
    {
        if ($block['name'] !== $block_name) {
            return [];
        }

        $show_selected = get_field('show_selected');

        if ($show_selected) {
            $selected = get_field($field_name);
            if (!empty($selected)) {
                return Timber::get_posts([
                    'post_type'      => $post_type,
                    'post__in'       => $selected,
                    'orderby'        => 'post__in',
                    'posts_per_page' => -1
                ]);
            }
            return [];
        }

        return Timber::get_posts([
            'post_type'      => $post_type,
            'posts_per_page' => -1
        ]);
    }

    /**
     * Block specific methods
     */
    private static function get_posts_archive($block)
    {
        if ($block['name'] === 'acf/posts-archive') {
            $paged = (get_query_var('paged')) ? get_query_var('paged') : 1;
            $args = [
                'post_type' => 'post',
                'paged'     => $paged,
                'orderby'   => 'post_date',
                'order'     => 'DESC',
            ];
            $query = new WP_Query($args);
            $posts = Timber::get_posts($args);
            return [
                'posts'      => $posts,
                'yp_pagination' => self::generate_pagination($query, $paged),
            ];
        }
        return;
    }

    private static function get_faqs($block)
    {
        return self::get_related_posts($block, 'acf/faqs', 'faq', 'faqs');
    }

    private static function get_testimonials($block)
    {
        return self::get_related_posts($block, 'acf/testimonial-carousel', 'testimonial', 'testimonials');
    }


    public static function timber_acf_path_render($slug, $is_preview)
    {
        $directories = self::timber_block_directory_getter();
        $ret = [];

        $preview_identifier = apply_filters('timber/acf-gutenberg-blocks-preview-identifier', '-preview');

        foreach ($directories as $directory) {
            if ($is_preview) {
                $ret[] = $directory . "/{$slug}{$preview_identifier}.twig";
            }
            $ret[] = $directory . "/{$slug}.twig";
        }

        return $ret;
    }

    public static function timber_block_directory_getter()
    {
        $directories = apply_filters('timber/acf-gutenberg-blocks-templates', ['views/blocks']);
        return array_merge($directories, self::timber_blocks_subdirectories($directories));
    }

    public static function timber_blocks_subdirectories($directories)
    {
        $ret = [];

        foreach ($directories as $base_directory) {
            if (!file_exists(locate_template($base_directory))) {
                continue;
            }

            $template_directory = new RecursiveDirectoryIterator(
                locate_template($base_directory),
                FilesystemIterator::KEY_AS_PATHNAME | FilesystemIterator::CURRENT_AS_SELF
            );

            foreach ($template_directory as $directory) {
                if ($directory->isDir() && !$directory->isDot()) {
                    $ret[] = $base_directory . '/' . $directory->getFilename();
                }
            }
        }

        return $ret;
    }
}
