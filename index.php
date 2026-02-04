<?php
// --- 1. 环境优化配置 ---
error_reporting(E_ALL); 
ini_set('display_errors', 1); // 强制显示错误，方便排查
set_time_limit(120); 

// --- 2. 配置信息 ---
$apiKey = 'AlQFjz0JcRNSLRw20hWIA0NOCuiAGAuSP4HruBOb8cHtIPoLA0wXJQQJ99CBACi0881XJ3w3AAALACOGaV2b';
$endpoint = 'https://receipt-ai-vision-01.cognitiveservices.azure.com/';
$ocrLogFile = 'ocr.log';
$csvFile = 'result.csv';

$displayResults = [];
$errorMsg = "";

// --- 3. 核心逻辑 ---
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_FILES['files'])) {
    try {
        // 【纯净版说明】暂时注释掉数据库连接，确保 AI 流程跑通
        /*
        $dbServer = "receipt-sql-server-24jn0245.database.windows.net";
        $dbName = "receipt-db";
        $dbUser = "jnsql";
        $dbPass = 'Pa$$word1234';
        $conn = new PDO("sqlsrv:server=$dbServer;Database=$dbName", $dbUser, $dbPass);
        */

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
            
            // 检查是否有 API 错误
            if(curl_errno($ch)) { throw new Exception("CURL 错误: " . curl_error($ch)); }

            preg_match('/Operation-Location: (.*)/i', $response, $matchesURL);
            $resultUrl = isset($matchesURL[1]) ? trim($matchesURL[1]) : null;
            curl_close($ch);

            if (!$resultUrl) {
                throw new Exception("无法获取 Operation-Location。请检查 API Key 和 Endpoint 是否正确。");
            }

            // B. 轮询结果
            $isDone = false;
            $statusData = [];
            $retryCount = 0;
            while (!$isDone && $retryCount < 40) {
                sleep(2);
                $ch2 = curl_init($resultUrl);
                curl_setopt($ch2, CURLOPT_HTTPHEADER, ['Ocp-Apim-Subscription-Key: ' . $apiKey]);
                curl_setopt($ch2, CURLOPT_RETURNTRANSFER, true);
                $jsonResponse = curl_exec($ch2);
                $statusData = json_decode($jsonResponse, true);
                
                if (isset($statusData['status']) && $statusData['status'] == 'succeeded') {
                    $isDone = true;
                } elseif (isset($statusData['status']) && $statusData['status'] == 'failed') {
                    throw new Exception("AI 解析失败。");
                }
                curl_close($ch2);
                $retryCount++;
            }

            // C. 立即写入日志 (确保符合课题要求)
            file_put_contents($ocrLogFile, "--- SCAN [" . date('Y-m-d H:i:s') . "] ---\n" . $jsonResponse . "\n\n", FILE_APPEND);

            // D. 解析数据
            $doc = $statusData['analyzeResult']['documents'][0]['fields'] ?? [];
            $items = $doc['Items']['valueArray'] ?? [];
            $totalAmount = $doc['Total']['valueNumber'] ?? 0;

            foreach ($items as $item) {
                $rawName = $item['valueObject']['Description']['valueString'] ?? '不明商品';
                // 严格按照课题要求：去除“轻”、空格、◎
                $cleanName = str_replace(['轻', '◎', ' ', '　', '*'], '', $rawName);
                $price = (int)($item['valueObject']['TotalPrice']['valueNumber'] ?? 0);

                if ($price > 0) {
                    $displayResults[] = ['name' => $cleanName, 'price' => $price];
                }
            }
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
        $errorMsg = "系统提示: " . $e->getMessage();
    }
}
?>

<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <title>收据识别测试版</title>
    <style>
        body { font-family: sans-serif; margin: 30px; }
        .res-table { width: 100%; border-collapse: collapse; }
        .res-table td, .res-table th { border: 1px solid #ddd; padding: 8px; }
        .error-box { background: #fee; color: #b00; padding: 15px; border: 1px solid #b00; margin-bottom: 20px; }
    </style>
</head>
<body>
    <h1>全家收据 OCR (纯净测试版)</h1>
    
    <?php if ($errorMsg): ?>
        <div class="error-box"><?= $errorMsg ?></div>
    <?php endif; ?>

    <form method="post" enctype="multipart/form-data">
        <input type="file" name="files[]" multiple>
        <button type="submit">上传并识别</button>
    </form>

    <?php if (!empty($displayResults)): ?>
        <h3>解析结果：</h3>
        <table class="res-table">
            <tr><th>内容</th><th>金额</th></tr>
            <?php foreach ($displayResults as $res): ?>
            <tr>
                <td><?= htmlspecialchars($res['name']) ?></td>
                <td><?= number_format($res['price']) ?></td>
            </tr>
            <?php endforeach; ?>
        </table>
        <p>
            <a href="result.csv">下载 CSV</a> | 
            <a href="ocr.log">查看 ocr.log</a>
        </p>
    <?php endif; ?>
</body>
</html>