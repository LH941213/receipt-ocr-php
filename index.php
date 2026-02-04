<?php
// --- 1. 配置信息 ---
$apiKey = 'AlQFjz0JcRNSLRw20hWIA0NOCuiAGAuSP4HruBOb8cHtIPoLA0wXJQQJ99CBACi0881XJ3w3AAALACOGaV2b';
$endpoint = 'https://receipt-ai-vision-01.cognitiveservices.azure.com/';
$ocrLogFile = 'ocr.log';
$csvFile = 'result.csv';

// 数据库配置 (从你的连接字符串提取)
$dbServer = "receipt-sql-server-24jn0245.database.windows.net";
$dbName = "receipt-db";
$dbUser = "jnsql";
$dbPass = 'Pa$$word1234';

$displayResults = [];

// --- 2. 逻辑处理：当用户点击上传按钮时 ---
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_FILES['files'])) {
    try {
        // 连接 Azure SQL Database
        $conn = new PDO("sqlsrv:server=$dbServer;Database=$dbName", $dbUser, $dbPass);
        $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        foreach ($_FILES['files']['tmp_name'] as $tmpName) {
            if (empty($tmpName)) continue;

            // A. 调用 Azure Document Intelligence (Receipt模型)
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
            $resultUrl = trim($matchesURL[1]);
            curl_close($ch);

            // B. 轮询等待 AI 结果
            $isDone = false;
            $jsonResponse = "";
            while (!$isDone) {
                sleep(2); 
                $ch2 = curl_init($resultUrl);
                curl_setopt($ch2, CURLOPT_HTTPHEADER, ['Ocp-Apim-Subscription-Key: ' . $apiKey]);
                curl_setopt($ch2, CURLOPT_RETURNTRANSFER, true);
                $jsonResponse = curl_exec($ch2);
                $statusData = json_decode($jsonResponse, true);
                if (isset($statusData['status']) && $statusData['status'] == 'succeeded') $isDone = true;
                curl_close($ch2);
            }

            // C. 写入 ocr.log (课题要求)
            file_put_contents($ocrLogFile, "--- SCAN [" . date('Y-m-d H:i:s') . "] ---\n" . $jsonResponse . "\n\n", FILE_APPEND);

            // D. 解析数据并存入数据库
            $doc = $statusData['analyzeResult']['documents'][0]['fields'] ?? [];
            $items = $doc['Items']['valueArray'] ?? [];
            $totalAmount = $doc['Total']['valueNumber'] ?? 0;

            $stmt = $conn->prepare("INSERT INTO receipts (item_name, price, is_total) VALUES (:name, :price, :is_total)");

            foreach ($items as $item) {
                $rawName = $item['valueObject']['Description']['valueString'] ?? 'Unknown';
                // 按照课题要求：去除“轻”、空格、◎
                $cleanName = str_replace(['轻', '◎', ' ', '　'], '', $rawName);
                $price = (int)($item['valueObject']['TotalPrice']['valueNumber'] ?? 0);

                $stmt->execute(['name' => $cleanName, 'price' => $price, 'is_total' => 0]);
                $displayResults[] = ['name' => $cleanName, 'price' => $price];
            }
            
            // 存入合计行
            $stmt->execute(['name' => '合计', 'price' => (int)$totalAmount, 'is_total' => 1]);
            $displayResults[] = ['name' => '合计', 'price' => (int)$totalAmount];
        }

        // E. 生成 CSV 文件 (课题要求)
        $fp = fopen($csvFile, 'w');
        fprintf($fp, chr(0xEF).chr(0xBB).chr(0xBF)); // 防止Excel乱码
        foreach ($displayResults as $row) {
            fputcsv($fp, [$row['name'], $row['price']]);
        }
        fclose($fp);

    } catch (Exception $e) {
        $errorMsg = "错误: " . $e->getMessage();
    }
}
?>

<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <title>FamilyMart 收据识别系统</title>
    <style>
        body { font-family: sans-serif; margin: 40px; }
        .result-table { border-collapse: collapse; width: 100%; margin-top: 20px; }
        .result-table th, .result-table td { border: 1px solid #ccc; padding: 10px; text-align: left; }
        .total-row { background-color: #f9f9f9; font-weight: bold; }
        .nav-links { margin-top: 20px; padding: 15px; background: #eee; }
    </style>
</head>
<body>
    <h1>全家收据识别上传 (PHP + Azure)</h1>
    
    <form action="" method="post" enctype="multipart/form-data">
        <p>请选择多张全家收据照片：</p>
        <input type="file" name="files[]" multiple accept="image/*">
        <button type="submit" style="padding: 10px 20px;">上传并自动提取内容</button>
    </form>

    <?php if (isset($errorMsg)): ?>
        <p style="color:red;"><?= $errorMsg ?></p>
    <?php endif; ?>

    <?php if (!empty($displayResults)): ?>
        <h2>提取出的商品列表：</h2>
        <table class="result-table">
            <tr><th>商品名</th><th>金额</th></tr>
            <?php foreach ($displayResults as $item): ?>
                <tr class="<?= $item['name'] == '合计' ? 'total-row' : '' ?>">
                    <td><?= htmlspecialchars($item['name']) ?></td>
                    <td>¥<?= number_format($item['price']) ?></td>
                </tr>
            <?php endforeach; ?>
        </table>

        <div class="nav-links">
            <strong>文件下载：</strong>
            <a href="<?= $csvFile ?>" target="_blank">下载 result.csv</a> | 
            <a href="<?= $ocrLogFile ?>" target="_blank">查看 ocr.log</a>
        </div>
    <?php endif; ?>
</body>
</html>