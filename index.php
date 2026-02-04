<?php
// --- 1. 环境优化配置 ---
error_reporting(E_ALL & ~E_NOTICE & ~E_WARNING); // 隐藏警告，防止阻塞
ini_set('display_errors', 0);
set_time_limit(120); // 增加运行时间到120秒，适应免费版速度

// --- 2. 配置信息 ---
$apiKey = 'AlQFjz0JcRNSLRw20hWIA0NOCuiAGAuSP4HruBOb8cHtIPoLA0wXJQQJ99CBACi0881XJ3w3AAALACOGaV2b';
$endpoint = 'https://receipt-ai-vision-01.cognitiveservices.azure.com/';
$ocrLogFile = 'ocr.log';
$csvFile = 'result.csv';

// 数据库配置
$dbServer = "receipt-sql-server-24jn0245.database.windows.net";
$dbName = "receipt-db";
$dbUser = "jnsql";
$dbPass = 'Pa$$word1234';

$displayResults = [];

// --- 3. 核心逻辑 ---
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_FILES['files'])) {
    try {
        $conn = new PDO("sqlsrv:server=$dbServer;Database=$dbName", $dbUser, $dbPass);
        $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        foreach ($_FILES['files']['tmp_name'] as $tmpName) {
            if (empty($tmpName)) continue;

            // A. 调用 Azure AI
            $analyzeUrl = $endpoint . "documentintelligence/documentModels/prebuilt-receipt:analyze?api-version=2023-07-31";
            $imgData = file_get_contents($tmpName);

            $ch = curl_init($analyzeUrl);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Content-Type: application/octet-stream',
                'Ocp-Apim-Subscription-Key: ' . $apiKey
            ]);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $imgData);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HEADER, true);
            $response = curl_exec($ch);

            preg_match('/Operation-Location: (.*)/i', $response, $matchesURL);
            $resultUrl = isset($matchesURL[1]) ? trim($matchesURL[1]) : null;
            curl_close($ch);

            if (!$resultUrl) continue;

            // B. 轮询结果
            $isDone = false;
            $statusData = [];
            $retryCount = 0;
            while (!$isDone && $retryCount < 30) {
                sleep(2);
                $ch2 = curl_init($resultUrl);
                curl_setopt($ch2, CURLOPT_HTTPHEADER, ['Ocp-Apim-Subscription-Key: ' . $apiKey]);
                curl_setopt($ch2, CURLOPT_RETURNTRANSFER, true);
                $jsonResponse = curl_exec($ch2);
                $statusData = json_decode($jsonResponse, true);
                if (isset($statusData['status']) && $statusData['status'] == 'succeeded') $isDone = true;
                curl_close($ch2);
                $retryCount++;
            }

            // C. 记录日志
            file_put_contents($ocrLogFile, "--- SCAN [" . date('Y-m-d H:i:s') . "] ---\n" . $jsonResponse . "\n\n", FILE_APPEND);

            // D. 解析并存入数据库
            $doc = $statusData['analyzeResult']['documents'][0]['fields'] ?? [];
            $items = $doc['Items']['valueArray'] ?? [];
            $totalAmount = $doc['Total']['valueNumber'] ?? 0;

            $stmt = $conn->prepare("INSERT INTO receipts (item_name, price, is_total) VALUES (:name, :price, :is_total)");

            foreach ($items as $item) {
                $rawName = $item['valueObject']['Description']['valueString'] ?? '不明商品';
                $cleanName = str_replace(['轻', '◎', ' ', '　', '*'], '', $rawName);
                $price = (int)($item['valueObject']['TotalPrice']['valueNumber'] ?? 0);

                if ($price > 0) {
                    $stmt->execute(['name' => $cleanName, 'price' => $price, 'is_total' => 0]);
                    $displayResults[] = ['name' => $cleanName, 'price' => $price];
                }
            }
            
            // 合计行
            $stmt->execute(['name' => '合计', 'price' => (int)$totalAmount, 'is_total' => 1]);
            $displayResults[] = ['name' => '合计', 'price' => (int)$totalAmount];
        }

        // E. 生成 CSV
        $fp = fopen($csvFile, 'w');
        fprintf($fp, chr(0xEF).chr(0xBB).chr(0xBF)); 
        foreach ($displayResults as $row) {
            fputcsv($fp, [$row['name'], $row['price']]);
        }
        fclose($fp);

    } catch (Exception $e) {
        $errorMsg = "系统繁忙或数据库连接超时，请稍后再试。";
    }
}
?>