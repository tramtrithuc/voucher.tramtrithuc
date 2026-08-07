<?php
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// ====================================================
// CẤU HÌNH THÔNG TIN TÀI KHOẢN TẠI ĐÂY
// ====================================================
$apiKey     = "afp_live_14a2013cb04ff8b4eb9ed0413f85ced35cb06c23c326069515b1ea72fd9d135c";
$toolIdFb   = "cmrx2iszs01t301nz84h9cpn7";
$toolIdIg   = "cmrx2kknx01t901nz8237js38";

// Tùy chọn Mức hoa hồng sàn cá nhân (nếu muốn tính chính xác theo Tier của bạn)
$userBaseRate = 8;      // Ví dụ: 8% (nhập 8 hoặc 0.08)
$userCap      = 40000;  // Trần hoa hồng sàn (mặc định 40,000 VNĐ)

// Lấy dữ liệu gửi lên
$input       = json_decode(file_get_contents('php://input'), true);
$originalUrl = trim($input['originalUrl'] ?? '');
$toolType    = $input['toolType'] ?? 'facebook';

if (empty($originalUrl)) {
    echo json_encode(["success" => false, "message" => "Vui lòng dán link Shopee!"]);
    exit();
}

// Chọn Tool ID tương ứng
$selectedToolId = ($toolType === 'instagram') ? $toolIdIg : $toolIdFb;

// ----------------------------------------------------
// BƯỚC 1: XỬ LÝ LINK & TRẮC NGHIỆM LẤY ITEM_ID
// ----------------------------------------------------
$resolvedUrl = expandUrl($originalUrl);
$itemId      = extractItemId($resolvedUrl);

// ----------------------------------------------------
// BƯỚC 2: GỌI API ADDLIVETAG LẤY CHI TIẾT SẢN PHẨM & HOA HỒNG
// ----------------------------------------------------
$productData = null;

if (!empty($itemId)) {
    $addLiveTagUrl = "https://data.addlivetag.com/product-data/product-data.php?item_id={$itemId}&base_rate={$userBaseRate}&cap={$userCap}";
    $productData   = fetchProductDataAddLiveTag($addLiveTagUrl);
}

// Fallback: nếu không tách được itemId, gọi bằng url
if (!$productData) {
    $addLiveTagUrl = "https://data.addlivetag.com/product-data/product-data.php?url=" . urlencode($resolvedUrl);
    $productData   = fetchProductDataAddLiveTag($addLiveTagUrl);
}

// ----------------------------------------------------
// BƯỚC 3: GỌI AFFIPAD CONVERT LINK AFFILIATE (SANVOUCHER.AFP.AD)
// ----------------------------------------------------
$affiliateUrl = '';
$convertData  = callAffipadApi('https://api.affipad.com/v1/convert', [
    'url'    => $resolvedUrl,
    'toolId' => (string)$selectedToolId
], $apiKey);

$affiliateUrl = $convertData['short_link'] 
             ?? $convertData['data']['short_link'] 
             ?? $convertData['affiliate_url'] 
             ?? $resolvedUrl;

// ----------------------------------------------------
// BƯỚC 4: TRẢ KẾT QUẢ CHO FRONTEND
// ----------------------------------------------------
echo json_encode([
    'success'      => true,
    'affiliateUrl' => $affiliateUrl,
    'itemId'       => $itemId,
    'product'      => $productData
]);
exit();


// ====================================================
// CÁC HÀM XỬ LÝ KỸ THUẬT
// ====================================================

// 1. Giải mã link rút gọn s.shopee.vn / vn.shp.ee ở phía client/server
function expandUrl($url) {
    if (strpos($url, 's.shopee.vn') === false && strpos($url, 'shp.ee') === false) {
        return $url;
    }
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36');
    curl_setopt($ch, CURLOPT_TIMEOUT, 5);
    curl_exec($ch);
    $effectiveUrl = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
    curl_close($ch);
    return $effectiveUrl ? $effectiveUrl : $url;
}

// 2. Tách item_id từ URL Shopee
function extractItemId($url) {
    // Dạng 1: -i.123456.78901234
    if (preg_match('/-i\.\d+\.(\d+)/', $url, $matches)) {
        return $matches[1];
    }
    // Dạng 2: /product/123456/78901234
    if (preg_match('/\/product\/\d+\/(\d+)/', $url, $matches)) {
        return $matches[1];
    }
    // Dạng 3: Số cuối đường dẫn
    if (preg_match('/\/(\d+)(?:\?|$)/', $url, $matches)) {
        return $matches[1];
    }
    return null;
}

// 3. Lấy dữ liệu sản phẩm từ data.addlivetag.com
function fetchProductDataAddLiveTag($apiUrl) {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $apiUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 6);
    curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64)');
    
    $response = curl_exec($ch);
    curl_close($ch);

    if (!$response) return null;

    $json = json_decode($response, true);
    if (($json['status'] ?? '') === 'success' && !empty($json['productInfo'])) {
        $info = $json['productInfo'];
        return [
            'itemId'            => $info['itemId'] ?? '',
            'name'              => $info['productName'] ?? '',
            'image'             => $info['imageUrl'] ?? '',
            'price'             => $info['price'] ?? 0,
            'sales'             => $info['sales'] ?? 0,
            'rating'            => $info['rating'] ?? 0,
            'shopName'          => $info['shopName'] ?? 'Shopee',
            'totalRatePercent'  => $info['totalRatePercent'] ?? 0,
            'commission'        => $info['commission'] ?? 0,
            'sellerCommission'  => $info['sellerComFinal'] ?? 0,
            'shopeeCommission'  => $info['shopeeComFinal'] ?? 0,
            'isXtra'            => $info['isXtra'] ?? false
        ];
    }
    return null;
}

// 4. Gọi API Convert của Affipad
function callAffipadApi($apiUrl, $payload, $apiKey) {
    $ch = curl_init($apiUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . $apiKey,
        'Content-Type: application/json'
    ]);
    curl_setopt($ch, CURLOPT_TIMEOUT, 6);
    $response = curl_exec($ch);
    curl_close($ch);
    return json_decode($response, true);
}
