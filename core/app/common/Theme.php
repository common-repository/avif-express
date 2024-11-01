<?php

namespace Avife\common;

if (!defined('ABSPATH')) exit;

class Theme
{

    public static function ajaxGetCurrentTheme()
    {
        if (wp_verify_nonce($_POST['avife_nonce'], 'avife_nonce') == false) wp_die();
        echo json_encode(self::getCurrentTheme());
        wp_die();
    }

    /**
     * provides information about current theme
     * @return array[]
     */
    public static function getCurrentTheme(): array
    {
        $active_theme = wp_get_theme();

        $data = array(
            'theme_name' => $active_theme->get('Name'),
            'is_child' => is_child_theme(), //to remove
            'theme_root' => get_theme_root(), //to remove
            'theme_dir' => get_stylesheet_directory(), // to remove
            'files' => array(
                'converted' => intval(count(self::themeFilesConverted())),
                'total' => intval(count(self::themesFilesTotal()))
            ),
        );

        return array($data);
    }

    public static function ajaxThemeFilesConvert()
    {
        if (!wp_verify_nonce($_POST['avife_nonce'], 'avife_nonce')) wp_die();

        $isCloudEngine = '0';
        if (Options::getConversionEngine() == 'cloud') $isCloudEngine = '1';

        if (!Utility::isLocalAvifConversionSupported() && $isCloudEngine == '0') wp_die();
        echo json_encode(self::themeFilesConvert());
        wp_die();
    }


    /**
     * themeFilesConvert
     * Converts all unconverted images inside theme directory
     * @return boolean
     */
    public static function themeFilesConvert()
    {
        $themeDirs = self::avif_get_theme_dirs();
        $filePaths = [];
        foreach ($themeDirs as $themeDir) {
            $filePaths = array_merge($filePaths, self::findFiles($themeDir, array("png", "jpg", "webp", "jpeg"), 1));
        }
        if (empty($filePaths) || gettype($filePaths) != 'array') return null;

        /**
         * Checking if 'set_time_limit' can be set or not
         * if not don't do anything
         */
        if (!Setting::avif_set_time_limit()) return false;
        $quality = Options::getImageQuality();
        $speed = Options::getComSpeed();

        $counter = 1;
        $keepAlive = 0;


        if (Options::getConversionEngine() == 'local') {
            foreach ($filePaths as $filePath) {

                $dest = (string)rtrim($filePath, '.' . pathinfo($filePath, PATHINFO_EXTENSION)) . '.avif';
                Image::convert($filePath, $dest, $quality, $speed);
                if ($counter == 5) {
                    return 'keep-alive';
                }
                $counter++;
            }
        }

        if (Options::getConversionEngine() == 'cloud') {
            //only allow 20 images per batch
            if (count($filePaths) > 20) {
                $filePaths = array_slice($filePaths, 0, 20);
                $keepAlive = 1;
            }
            $unConvertedAttachmentUrls = Utility::pathToAttachmentUrl($filePaths);

            $cs = Image::cloudConvert($unConvertedAttachmentUrls);
            if ($cs === false) return 'ccfail';
            if ($cs === 'ccover') return 'ccover';
            if ($keepAlive == 1) return 'keep-alive';

        }

        return true;
    }

    /**
     * ajaxThemeFilesDelete
     * ajax handle for themeFilesDelete
     * @return void
     */
    public static function ajaxThemeFilesDelete()
    {
        if (!wp_verify_nonce($_POST['avife_nonce'], 'avife_nonce')) wp_die();
        echo json_encode(self::themeFilesDelete());
        wp_die();
    }

    /**
     * themeFilesDelete
     * Deletes .avif files from theme folders.
     * In case of child theme, delete from parent and child.
     * @return boolean
     */
    public static function themeFilesDelete() : bool
    {

        $filePaths = self::themeFilesConverted();
        /**
         * if no file found , terminating the process.
         */
        if (empty($filePaths)) return false;
        /**
         * iterating through file paths
         *
         */
        return Utility::deleteFiles($filePaths);
    }


    /**
     * themeFilesConverted
     * @return array returns an array containing the paths of jpg,webp and jpeg images in the theme dir(s) that are already converted
     */
    public static function themeFilesConverted(): array
    {
        $themeDirs = self::avif_get_theme_dirs();
        $convertedFiles = [];
        foreach ($themeDirs as $themeDir) {

            $convertedFiles = array_merge($convertedFiles, self::findFiles($themeDir, array("png", "jpg", "webp", "jpeg"), -1));
        }
        return $convertedFiles;
    }

    /**
     * themesFilesUnconverted
     * returns an array containing the paths of jpg,webp and jpeg images in the theme dir
     * that ate yet to get converted
     * @return array
     */
    public static function themesFilesUnconverted() : array
    {
        $themeDirs = self::avif_get_theme_dirs();
        $unconvertedFiles = [];
        foreach ($themeDirs as $themeDir) {
            $unconvertedFiles = array_merge($unconvertedFiles, self::findFiles($themeDir, array("png", "jpg", "webp", "jpeg"), 1));
        }
        return $unconvertedFiles;
    }

    /**
     * themesFilesTotal
     * returns an array containing the paths of jpg,webp and jpeg images in the theme dir
     * @return array
     */
    public static function themesFilesTotal() : array
    {
        $themeDirs = self::avif_get_theme_dirs();
        $totalFiles = [];
        foreach ($themeDirs as $themeDir) {
            $totalFiles = array_merge($totalFiles, self::findFiles($themeDir, array("png", "jpg", "webp", "jpeg"), 0));
        }
        return $totalFiles;
    }


    /**
     * findFiles
     * @param string $basePath : root path to start the search
     * @param array $exts : extension of files to look for
     * @param int $hasAvif : 0  - All , 1 - Unconverted, -1 - Converted
     * @return array file paths
     */
    public static function findFiles(string $basePath, array $exts, int $hasAvif = 0) : array
    {
        /**
         * To store the paths
         */
        $files = [];
        /**
         * iterating through provided extensions
         */
        foreach ($exts as $ext) {
            /**
             * looking at provided directory path and storing all the file path with specific extension($ext)
             */
            $baseFiles = glob("$basePath/*.$ext");

            /**
             * iterating through file paths
             */
            foreach ($baseFiles as $key => $baseFile) {
                /**
                 * creating .avif file path from the source file path
                 */
                $avifFile = rtrim($baseFile, '.' . pathinfo($baseFile, PATHINFO_EXTENSION)) . '.avif';
                /**
                 * $hasAvif = 1 , unconverted files.
                 * $hasAvif = -1, converted files
                 * $hasAvif = 0, do nothing (keeping all)
                 */
                if (file_exists($avifFile) && $hasAvif == 1) {
                    unset($baseFiles[$key]);
                }
                if (!file_exists($avifFile) && $hasAvif == -1) {
                    unset($baseFiles[$key]);
                }
            }
            /**
             * storing the source file paths
             */
            $files = array_merge($files, $baseFiles);
        }
        /**
         * finding paths of all subdirectories within provided base directory
         * and storing them
         * @type array
         */
        $sub_dirs = glob("$basePath/*", GLOB_ONLYDIR);

        /**
         * iterating through sub_directories
         */
        foreach ($sub_dirs as $sub_dir) {
            /**
             * calling itself with sub_directory with originally provided extension and return type.
             * RECURSION
             */
            $sub_files = self::findFiles($sub_dir, $exts, $hasAvif);
            /**
             * And storing that
             */
            $files = array_merge($files, $sub_files);
        }
        /**
         * Finally returning all
         */
        return $files;
    }

    /**
     * avif_get_theme_dirs
     * @return array returns the theme path(s). In case of child theme, return parent and child theme path
     */
    public static function avif_get_theme_dirs(): array
    {
        $themes = array();
        if (is_child_theme()) {
            $themes[] = get_stylesheet_directory();
            $themes[] = get_template_directory();
        } else {
            $themes[] = get_template_directory();
        }

        return $themes;
    }
}
