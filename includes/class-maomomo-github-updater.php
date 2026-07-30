<?php
/**
 * GitHub Release 更新检查。
 *
 * @package MaoMoMo_TinyPNG_Media
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

use YahnisElsts\PluginUpdateChecker\v5\PucFactory;
use YahnisElsts\PluginUpdateChecker\v5p7\Vcs\Api;

final class MaoMoMo_GitHub_Updater {
    const REPOSITORY_URL = 'https://github.com/maomomo-eth/maomomo-tinypng-media/';
    const PLUGIN_SLUG    = 'maomomo-tinypng-media';

    /**
     * 初始化更新检查器。
     *
     * @param string $plugin_file 插件入口文件的绝对路径。
     * @return void
     */
    public static function init( $plugin_file ) {
        $checker = PucFactory::buildUpdateChecker(
            self::REPOSITORY_URL,
            $plugin_file,
            self::PLUGIN_SLUG
        );

        $checker->setBranch( 'main' );
        $checker->getVcsApi()->enableReleaseAssets(
            '/^maomomo-tinypng-media\.zip$/i',
            Api::REQUIRE_RELEASE_ASSETS
        );

        // 禁止回退到普通 Tag 或 main，只允许安装带指定 ZIP 附件的正式 Release。
        $checker->addFilter(
            'vcs_update_detection_strategies',
            array( __CLASS__, 'only_latest_release' )
        );
    }

    /**
     * 只保留 GitHub 最新正式 Release 检查策略。
     *
     * @param array $strategies 更新来源策略。
     * @return array
     */
    public static function only_latest_release( $strategies ) {
        if ( ! isset( $strategies[ Api::STRATEGY_LATEST_RELEASE ] ) ) {
            return array();
        }

        return array(
            Api::STRATEGY_LATEST_RELEASE => $strategies[ Api::STRATEGY_LATEST_RELEASE ],
        );
    }
}
