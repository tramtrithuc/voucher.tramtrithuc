<?php
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");

// Xử lý Request OPTIONS (CORS preflight)
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// ====================================================
// CẤU HÌNH THÔNG TIN AFFIPAD CỦA BẠN TẠI ĐÂY:
// ====================================================
$apiKey     = "afp_live_14a2013cb04ff8b4eb9ed0413f85ced35cb06c23c326069515b1ea72fd9d135c";
$toolIdFb   = "cmrx2iszs01t301nz84h9cpn7";
$toolIdIg   = "cmrx2kknx01t901nz8237js38";

// Lấy dữ liệu gửi lên từ Client
$input = json_decode(file_get_contents('php://input'), true);
$originalUrl = trim($input['originalUrl'] ?? '');
$toolType = $input['toolType'] ?? 'facebook';

if (empty($originalUrl)) {
    echo json_encode(["success" => false, "message" => "Vui lòng dán link Shopee!"]);
    exit();
}

// 1. Phân loại Tool ID chuẩn theo kênh người dùng bấm (Facebook / Instagram)
$selectedToolId = ($toolType === 'instagram') ? $toolIdIg : $toolIdFb;

// 2. Giải mã link ngắn s.shopee.vn -> Link sản phẩm gốc (Cần thiết để lấy thông tin sản phẩm)
$fullShopeeUrl = expandUrl($originalUrl);

// 3. Gọi API Convert Link của Affipad
$convertData = callAffipadApi('https://api.affipad.com/v1/convert', [
    'url' => $fullShopeeUrl,
    'toolId' => (string)$selectedToolId
], $apiKey);

// ƯU TIÊN LẤY LINK RÚT GỌN (short_link) để điện thoại tự bật App Shopee
$affiliateUrl = $convertData['short_link'] 
             ?? $convertData['data']['short_link'] 
             ?? $convertData['affiliate_url'] 
             ?? $convertData['data']['affiliate_url'] 
             ?? '';

if (empty($affiliateUrl)) {
    $affiliateUrl = $fullShopeeUrl; // Fallback nếu API lỗi
}

// 4. Gọi API Lấy Thông Tin Sản Phẩm (Ảnh + Tên)
$product = null;
$prodData = callAffipadApi('https://api.affipad.com/v1/product-info', [
    'url' => $fullShopeeUrl
], $apiKey);

// Bóc tách đúng cấu trúc productInfo của Affipad
$rawProduct = $prodData['data']['productInfo'] ?? $prodData['data'] ?? null;
if ($rawProduct && (!empty($rawProduct['name']) || !empty($rawProduct['title']))) {
    $product = [
        'name' => $rawProduct['name'] ?? $rawProduct['title'],
        'image' => $rawProduct['image'] ?? $rawProduct['imageUrl'] ?? '',
        'price' => $rawProduct['price'] ?? $rawProduct['priceMin'] ?? 0,
        'priceBeforeDiscount' => $rawProduct['priceBeforeDiscount'] ?? $rawProduct['priceMinBeforeDiscount'] ?? 0,
        'currency' => $rawProduct['currency'] ?? 'VND'
    ];
}

// 5. Trả kết quả JSON về cho Frontend
echo json_encode([
    'success' => true,
    'affiliateUrl' => $affiliateUrl,
    'product' => $product
]);
exit();

// ====================================================
// CÁC HÀM BỔ TRỢ (HELPER FUNCTIONS)
// ====================================================

// Hàm mở rộng link s.shopee.vn -> Link sản phẩm gốc
function expandUrl($url) {
    if (strpos($url, 's.shopee.vn') === false && strpos($url, 'shp.ee') === false) {
        return $url;
    }
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36');
    curl_exec($ch);
    $redirectedUrl = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
    curl_close($ch);
    return $redirectedUrl ? $redirectedUrl : $url;
}

// Hàm gửi cURL request tới API Affipad
function callAffipadApi($apiUrl, $payload, $apiKey) {
    $ch = curl_init($apiUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . $apiKey,
        'Content-Type: application/json'
    ]);
    $response = curl_exec($ch);
    curl_close($ch);
    return json_decode($response, true);
}
