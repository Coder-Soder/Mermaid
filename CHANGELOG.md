# Mermaid Plugin Changelog

## Version 1.3.4 (2025-01-XX)

### 重大修复
- **修复主页列表页加载 mermaid 脚本导致布局错误的问题**
  - 在 `beforeRender` 中检测主页列表页，设置 `$isIndexPage` 标记
  - 在 `parseContent` 中，主页列表页完全跳过 mermaid 处理
  - 在 `footer` 中，主页列表页完全跳过脚本输出
  - 确保主页不会加载 mermaid 脚本，保持布局正常

- **修复文章页面 mermaid 解析失效的问题**
  - 优化 `parseContent` 逻辑，移除对文章页面的误判
  - 文章页面正常处理，不检查调用栈（避免误跳过）
  - 确保文章页面的 mermaid 代码块能正确转换为 div 元素

### 功能增强
- **增强摘要处理逻辑**
  - `filterExcerpt`：在摘要提取前拦截，彻底移除所有 mermaid 代码
  - `parseExcerpt`：作为第二层防护，使用循环处理确保彻底清除
  - 双重防护机制确保主页摘要不包含任何 mermaid 相关 HTML

- **优化脚本加载逻辑**
  - 只在非主页列表页输出 mermaid 脚本
  - 智能检测：服务端检测 + 前端 DOM 检测（按需加载模式）
  - 支持强制加载、按需加载、禁用三种模式

### 代码优化
- 移除冗余的调用栈检查逻辑（文章页面不再检查）
- 简化主页列表页判断（使用 `beforeRender` 中的 `$isIndexPage` 标记）
- 改进代码注释，增强可维护性

### 兼容性
- 完全兼容 Typecho 1.3.0-rc
- 兼容 memoo 主题
- 支持 PJAX 无刷新加载
- 兼容 Mermaid 10.x API

---

## Version 1.3.3 (Previous)

### 修复
- 修复按需加载模式下文章页 mermaid 无法渲染的问题
- 修复主页列表页布局错误问题（部分修复）

### 功能
- 添加 `filterExcerpt` 过滤器拦截
- 增强 `parseExcerpt` 移除逻辑

---

## Version 1.3.2

### 功能
- 初步实现 mermaid 渲染功能
- 支持按需加载和强制加载模式
- 支持懒加载（IntersectionObserver）

### 问题
- 主页列表页布局错误
- 按需加载在文章页失效

---

## 技术细节

### 关键修复点

1. **主页列表页判断**
   ```php
   // beforeRender 中设置标记
   self::$isIndexPage = $archive->is('index');
   
   // parseContent 中检查
   if (self::$isIndexPage) {
       return $content; // 完全跳过
   }
   
   // footer 中检查
   if (self::$isIndexPage) {
       return; // 不输出脚本
   }
   ```

2. **摘要处理流程**
   ```
   Typecho 流程：
   1. $this->content (触发 contentEx -> parseContent)
   2. filter('excerpt', $this->content) -> filterExcerpt (移除 mermaid)
   3. explode('<!--more-->') 提取摘要
   4. filter('excerptEx', $excerpt) -> parseExcerpt (再次清理)
   ```

3. **文章页面处理**
   - 文章页面 `$isIndexPage = false`
   - 不检查调用栈，直接处理
   - 正常转换和检测 mermaid

### 测试建议

1. **主页测试**
   - 检查 Network 面板，确认不加载 `mermaid.min.js`
   - 检查页面布局，确认右侧栏正常
   - 检查摘要长度，确认不超过 140 字

2. **文章页测试**
   - 检查 Network 面板，确认加载 `mermaid.min.js`
   - 检查页面源码，确认有 `<div class="mermaid">` 元素
   - 检查图表渲染，确认正确显示

3. **兼容性测试**
   - 测试 PJAX 加载
   - 测试懒加载模式
   - 测试不同 CDN 源

