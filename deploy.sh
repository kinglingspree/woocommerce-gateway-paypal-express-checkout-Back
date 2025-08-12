#!/bin/bash

# PayPal Express Checkout 插件打包脚本 - 包含 REST API v2 功能
echo "创建包含 REST API v2 功能的 PayPal 插件版本..."

# 获取脚本所在目录的绝对路径
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
echo "插件目录: $SCRIPT_DIR"

# 进入插件目录
cd "$SCRIPT_DIR"

# 获取版本号（来自 readme.txt 的 Stable tag）
VERSION=$(grep "^Stable tag:" readme.txt | awk '{print $3}')
if [ -z "$VERSION" ]; then
    echo "⚠️  无法从 readme.txt 获取版本号，使用默认版本"
    VERSION="1.0.0"
fi
echo "版本号: $VERSION"

# 创建releases目录
RELEASES_DIR="$SCRIPT_DIR/releases"
mkdir -p "$RELEASES_DIR"

# 目标目录 - 使用标准命名
DEST="$RELEASES_DIR/woocommerce-gateway-paypal-express-checkout"

# 准备干净的发布目录
rm -rf "$DEST"
mkdir -p "$DEST"

echo "正在同步插件文件（包含 REST API v2 功能）..."

# 复制核心插件文件
cp -f "woocommerce-gateway-paypal-express-checkout.php" "$DEST/"
cp -f "readme.txt" "$DEST/"
cp -f "changelog.txt" "$DEST/"

# 复制资源目录
if [ -d "assets" ]; then
    cp -r "assets" "$DEST/"
    echo "✓ 已复制 assets 目录"
fi

# 复制包含文件目录（包含我们的 REST API v2 文件）
if [ -d "includes" ]; then
    cp -r "includes" "$DEST/"
    echo "✓ 已复制 includes 目录（包含 REST API v2 类）"
fi

# 复制语言文件
if [ -d "languages" ]; then
    cp -r "languages" "$DEST/"
    echo "✓ 已复制 languages 目录"
fi

# 复制模板文件
if [ -d "templates" ]; then
    cp -r "templates" "$DEST/"
    echo "✓ 已复制 templates 目录"
fi

# 复制文档文件
if [ -f "DEVELOPER.md" ]; then
    cp -f "DEVELOPER.md" "$DEST/"
fi

if [ -f "REST_API_v2_README.md" ]; then
    cp -f "REST_API_v2_README.md" "$DEST/"
    echo "✓ 已复制 REST API v2 文档"
fi

# 验证关键的 REST API v2 文件是否存在
echo ""
echo "验证 REST API v2 文件..."
REST_FILES=(
    "includes/class-wc-gateway-ppec-rest-client.php"
    "includes/class-wc-gateway-ppec-parameter-mapper.php"
    "includes/class-wc-gateway-ppec-rest-adapter.php"
    "includes/class-wc-gateway-ppec-rest-settings.php"
    "includes/class-wc-gateway-ppec-client-factory.php"
    "includes/class-wc-gateway-ppec-rest-bootstrap.php"
)

for file in "${REST_FILES[@]}"; do
    if [ -f "$DEST/$file" ]; then
        echo "✓ $file"
    else
        echo "⚠️  缺失: $file"
    fi
done

# 创建 ZIP 文件
echo ""
echo "创建 ZIP 压缩包..."
cd "$RELEASES_DIR"

ZIP_NAME="woocommerce-gateway-paypal-express-checkout-$VERSION-rest-api-v2.zip"
zip -r "$ZIP_NAME" "woocommerce-gateway-paypal-express-checkout" -x "*/.DS_Store" "*/Thumbs.db"

# 获取文件大小
if [ -f "$ZIP_NAME" ]; then
    FILE_SIZE=$(ls -lh "$ZIP_NAME" | awk '{print $5}')
    echo ""
    echo "✅ REST API v2 版本打包完成！"
    echo ""
    echo "📁 输出文件："
    echo "   目录: $DEST"
    echo "   ZIP:  $ZIP_NAME ($FILE_SIZE)"
    echo "   路径: $RELEASES_DIR/$ZIP_NAME"
    echo ""
    echo "🚀 主要功能："
    echo "   • 默认启用 REST API v2"
    echo "   • OAuth 2.0 认证支持"
    echo "   • 完整的 NVP 到 REST 参数映射"
    echo "   • 向后兼容性保证"
    echo "   • 增强的日志记录到 php_error.log"
    echo "   • 灵活的迁移模式"
else
    echo "❌ 打包失败！"
    exit 1
fi
