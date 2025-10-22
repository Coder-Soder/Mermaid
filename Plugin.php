<?php
/**
 * Mermaid Plugin for Typecho
 * 
 * 专注于 Mermaid 图表渲染的 Typecho 插件
 * 提供完整、稳定、高性能的流程图、时序图、甘特图等图表渲染解决方案
 * 
 * @package Mermaid
 * @author Richard Yang
 * @version 1.3.2
 * @link https://your-domain.com/
 */

namespace TypechoPlugin\Mermaid;

use Typecho\Plugin\PluginInterface;
use Typecho\Widget\Helper\Form;
use Typecho\Widget\Helper\Form\Element\Radio;
use Typecho\Widget\Helper\Form\Element\Select;
use Typecho\Widget\Helper\Form\Element\Checkbox;
use Widget\Options;

class Plugin implements PluginInterface
{
    // 加载模式常量
    const LOAD_DISABLE = 0;
    const LOAD_SMART = 1;
    const LOAD_FORCE = 2;
    
    // CDN 源配置
    const CDN_SOURCES = [
        'jsdelivr' => [
            'name' => 'jsDelivr (推荐)',
            'mermaid' => 'https://cdn.jsdelivr.net/npm/mermaid@10/dist/mermaid.min.js'
        ],
        'unpkg' => [
            'name' => 'UNPKG',
            'mermaid' => 'https://unpkg.com/mermaid@10/dist/mermaid.min.js'
        ],
        'china' => [
            'name' => '国内镜像 (BootCDN)',
            'mermaid' => 'https://cdn.bootcdn.net/ajax/libs/mermaid/10.7.0/mermaid.min.js'
        ]
    ];
    
    // Mermaid 主题配置
    const MERMAID_THEMES = [
        'default' => '默认 (default)',
        'dark' => '暗黑 (dark)',
        'forest' => '森林 (forest)',
        'neutral' => '中性 (neutral)'
    ];
    
    // 检测状态
    private static $needMermaid = false;
    private static $resourceIncluded = false;

    /**
     * 激活插件
     */
    public static function activate()
    {
        \Typecho\Plugin::factory('Widget_Archive')->beforeRender = [__CLASS__, 'beforeRender'];
        \Typecho\Plugin::factory('Widget_Archive')->footer = [__CLASS__, 'footer'];
        \Typecho\Plugin::factory('Widget_Abstract_Contents')->contentEx = [__CLASS__, 'parseContent'];
        \Typecho\Plugin::factory('Widget_Abstract_Contents')->excerptEx = [__CLASS__, 'parseExcerpt'];
        \Typecho\Plugin::factory('Widget_Abstract_Comments')->contentEx = [__CLASS__, 'parseContent'];
        
        return _t('Mermaid 插件已激活，请配置相关设置。');
    }

    /**
     * 禁用插件
     */
    public static function deactivate()
    {
        return _t('Mermaid 插件已禁用。');
    }

    /**
     * 插件配置界面
     */
    public static function config(Form $form)
    {
        // ========== 核心设置部分 ==========
        echo '<h2>核心设置</h2>';
        
        // Mermaid 设置
        $mermaidMode = new Radio('mermaid_mode', [
            self::LOAD_DISABLE => _t('禁用'),
            self::LOAD_SMART => _t('智能按需加载 (推荐)'),
            self::LOAD_FORCE => _t('强制加载 (兼容性更好)')
        ], self::LOAD_SMART, _t('Mermaid 图表渲染'), _t('智能按需加载仅在检测到图表时加载资源，提升性能'));
        $form->addInput($mermaidMode);
        
        $mermaidTheme = new Select('mermaid_theme', self::MERMAID_THEMES, 'default', 
            _t('Mermaid 主题'), _t('选择图表的视觉主题'));
        $form->addInput($mermaidTheme);
        
        // ========== CDN 与性能设置 ==========
        echo '<h2>CDN 与性能</h2>';
        
        $cdnOptions = [];
        foreach (self::CDN_SOURCES as $key => $source) {
            $cdnOptions[$key] = _t($source['name']);
        }
        
        $cdnSource = new Radio('cdn_source', $cdnOptions, 'jsdelivr', 
            _t('CDN 源选择'), _t('选择资源加载的 CDN 服务商'));
        $form->addInput($cdnSource);
        
        $lazyLoad = new Radio('lazy_load', [
            '0' => _t('禁用'),
            '1' => _t('启用 (推荐)')
        ], '1', _t('懒加载'), _t('延迟渲染图表直到进入视口'));
        $form->addInput($lazyLoad);
        
        // ========== 高级功能设置 ==========
        echo '<h2>高级功能</h2>';
        
        $pjaxSupport = new Radio('pjax_support', [
            '0' => _t('禁用'),
            '1' => _t('启用')
        ], '1', _t('Pjax 兼容'), _t('支持单页应用动态重新渲染'));
        $form->addInput($pjaxSupport);
        
        $debugMode = new Radio('debug_mode', [
            '0' => _t('禁用'),
            '1' => _t('启用')
        ], '0', _t('调试模式'), _t('在控制台输出调试信息'));
        $form->addInput($debugMode);
    }

    /**
     * 个人配置界面
     */
    public static function personalConfig(Form $form)
    {
        // 暂无个人配置需求
    }

    /**
     * 页面渲染前处理
     */
    public static function beforeRender($archive)
    {
        // 重置检测状态
        self::$needMermaid = false;
        self::$resourceIncluded = false;
    }

    /**
     * 内容解析 - 回退到有效的转换逻辑
     */
    public static function parseContent($content, $widget = null)
    {
        if (empty($content)) {
            return $content;
        }
        
        $config = Options::alloc()->plugin('Mermaid');
        
        // 转换 Markdown 代码块为 mermaid div
        $content = self::convertMermaidCodeBlocks($content);
        
        // 检测内容类型
        if ($config->mermaid_mode != self::LOAD_DISABLE) {
            self::$needMermaid = self::$needMermaid || self::detectMermaid($content);
        }
        
        return $content;
    }

    /**
     * 转换 Markdown 代码块为 mermaid div - 有效的转换逻辑
     */
    private static function convertMermaidCodeBlocks($content)
    {
        // 匹配 Typecho 解析后的 Markdown 代码块结构
        $pattern = '/<pre><code class="lang-mermaid">(.*?)<\/code><\/pre>/s';
        
        return preg_replace_callback($pattern, function($matches) {
            if (empty($matches[1])) {
                return $matches[0];
            }
            
            $mermaidCode = trim($matches[1]);
            $mermaidCode = self::cleanMermaidCode($mermaidCode);
            
            return '<div class="mermaid">' . $mermaidCode . '</div>';
        }, $content);
    }

    /**
     * 清理 Mermaid 代码
     */
    private static function cleanMermaidCode($code)
    {
        // 移除开头和结尾的空白字符
        $code = trim($code);
        
        // 处理 HTML 实体编码
        $code = html_entity_decode($code, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        
        // 移除可能的多余转义字符
        $code = str_replace(['\\n', '\\t', '\\r'], ["\n", "\t", "\r"], $code);
        
        // 统一换行符
        $code = preg_replace('/\r\n?/', "\n", $code);
        
        // 移除多余的空行
        $code = preg_replace('/\n\s*\n/', "\n", $code);
        
        return $code;
    }

    /**
     * 摘要解析
     */
    public static function parseExcerpt($content, $widget = null)
    {
        // 在摘要中禁用复杂渲染以提高性能
        $content = preg_replace('/<div class="mermaid">.*?<\/div>/s', '[Mermaid图表]', $content);
        $content = preg_replace('/<pre><code class="lang-mermaid">.*?<\/code><\/pre>/s', '[Mermaid图表]', $content);
        
        return self::parseContent($content, $widget);
    }

    /**
     * 页脚资源输出
     */
    public static function footer()
    {
        // 防止重复输出
        if (self::$resourceIncluded) {
            return;
        }
        
        $config = Options::alloc()->plugin('Mermaid');
        $cdnSource = $config->cdn_source ?: 'jsdelivr';
        $cdnConfig = self::CDN_SOURCES[$cdnSource] ?? self::CDN_SOURCES['jsdelivr'];
        
        $resourceContent = '';
        
        // Mermaid 资源加载决策逻辑
        $isAvailableMermaid = $config->mermaid_mode == self::LOAD_FORCE || 
                             (self::$needMermaid && $config->mermaid_mode == self::LOAD_SMART);
        
        if ($isAvailableMermaid) {
            $resourceContent .= '<script src="' . $cdnConfig['mermaid'] . '"></script>';
            $resourceContent .= '<script>';
            $resourceContent .= 'document.addEventListener("DOMContentLoaded", function() {';
            $resourceContent .= '  if (typeof mermaid !== "undefined") {';
            $resourceContent .= '    mermaid.initialize({';
            $resourceContent .= '      startOnLoad: true,';
            $resourceContent .= '      theme: "' . ($config->mermaid_theme ?: 'default') . '",';
            $resourceContent .= '      securityLevel: "loose",';
            $resourceContent .= '      flowchart: { htmlLabels: true, curve: "basis" }';
            $resourceContent .= '    });';
            
            // 手动触发渲染以确保所有图表都被处理
            $resourceContent .= '    mermaid.run();';
            
            // Pjax 支持
            if ($config->pjax_support) {
                $resourceContent .= '    if (window.pjax) {';
                $resourceContent .= '      document.addEventListener("pjax:complete", function() {';
                $resourceContent .= '        setTimeout(() => {';
                $resourceContent .= '          mermaid.run();';
                $resourceContent .= '        }, 100);';
                $resourceContent .= '      });';
                $resourceContent .= '    }';
            }
            
            $resourceContent .= '  } else {';
            $resourceContent .= '    console.error("Mermaid failed to load");';
            $resourceContent .= '  }';
            $resourceContent .= '});';
            $resourceContent .= '</script>';
            
            // Mermaid CSS 样式
            $resourceContent .= '<style>';
            $resourceContent .= '.mermaid { margin: 1rem 0; text-align: center; background: white; padding: 10px; border-radius: 5px; border: 1px solid #e1e1e1; }';
            $resourceContent .= '.mermaid svg { max-width: 100%; height: auto; }';
            $resourceContent .= '.mermaid .label { font-family: inherit; }';
            
            // 移动端优化
            $resourceContent .= '@media (max-width: 768px) {';
            $resourceContent .= '  .mermaid { margin: 0.5rem 0; }';
            $resourceContent .= '}';
            
            // 打印样式
            $resourceContent .= '@media print {';
            $resourceContent .= '  .mermaid { break-inside: avoid; }';
            $resourceContent .= '}';
            $resourceContent .= '</style>';
        }
        
        // 输出调试信息
        if ($config->debug_mode) {
            $debugInfo = [
                'mermaid' => [
                    'need' => self::$needMermaid, 
                    'available' => $isAvailableMermaid
                ],
                'cdn_source' => $cdnSource
            ];
            $resourceContent .= '<!-- Mermaid Debug: ' . json_encode($debugInfo) . ' -->';
        }
        
        if (!empty($resourceContent)) {
            echo $resourceContent;
            self::$resourceIncluded = true;
        }
    }

    /**
     * 检测 Mermaid 图表 - 简化版本
     */
    private static function detectMermaid($content)
    {
        // 检测标准的 mermaid div
        if (preg_match('/<div class="mermaid">.*?<\/div>/s', $content)) {
            return true;
        }
        
        // 检测 Typecho 解析后的 Markdown 代码块
        if (preg_match('/<pre><code class="lang-mermaid">.*?<\/code><\/pre>/s', $content)) {
            return true;
        }
        
        return false;
    }
}