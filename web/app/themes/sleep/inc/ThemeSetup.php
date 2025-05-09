<?php

namespace YPTheme;

use Timber\Timber;
use Twig\TwigFunction;
use DirectoryIterator;
use FilesystemIterator;
use RecursiveDirectoryIterator;

class ThemeSetup
{
    public static function init()
    {
        add_action('after_setup_theme', [self::class, 'setup_theme']);
        add_action('after_setup_theme', [self::class, 'setup_menus']);
        add_filter('timber/context', [self::class, 'add_menus_to_timber']);
    }

    public static function setup_theme()
    {

        add_theme_support('title-tag');
        add_theme_support('post-thumbnails');
        add_theme_support('align-wide');
        add_theme_support('wp-block-styles');
        add_theme_support('editor-styles');

        self::customise_gravity_forms();

        remove_editor_styles();
    }

    public static function setup_menus()
    {

        register_nav_menus([
            'primary-navigation' => __('Primary Navigation'),
            'footer-navigation' => __('Footer Navigation'),
            'products-navigation' => __('Products Navigation'),
            'legal-navigation' => __('Legal Navigation'),
        ]);
    }

    public static function add_menus_to_timber($context)
    {
        $context['primary_nav'] = Timber::get_menu('primary-navigation');
        $context['discover_nav'] = Timber::get_menu('footer-navigation');
        $context['products_nav'] = Timber::get_menu('products-navigation');
        $context['legal_nav'] = Timber::get_menu('legal-navigation');
        return $context;
    }

    private static function customise_gravity_forms()
    {
        add_filter('gform_submit_button', function ($button, $form) {
            // Gravity form uses different classes for submit buttons if positioned below ar aside for some reason...
            $button = str_replace("class='gform_button", "class='gform_button primary", $button);
            $button = str_replace("class='gform-button", "class='gform-button primary", $button);
            return $button;
        }, 10, 2);
    }
}
