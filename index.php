<?php
// --- 1. 环境与错误处理 ---
error_reporting(E_ALL);
ini_set('display_errors', 1); // 开启报错，方便调试
set_time_limit(120); 

// --- 2. 配置信息 (已填入新 Key) ---
$apiKey = '13xSqpsRYAH9oeZG5N5XsRwcSwyegTHtni3Axisx0b2RMgWnpZNPJQQJ99CBACi0881XJ3w3AAALACOGRyKN';
$endpoint = 'https://receipt-ai-vision-01.cognitiveservices.azure.com/';
$ocrLogFile = 'ocr.log';
$csvFile = 'result.csv';

$displayResults = [];
$errorMsg = "";

// --- 3. 核心处理逻辑 ---
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_FILES['files'])) {
    try {
        // 【注意】如果需要数据库，请在后面自行开启。为了先拿 ocr.log，我们先跑通 AI 流程。

        foreach ($_FILES['files']['tmp_name'] as $tmpName) {
            if (empty($tmpName)) continue;

            $imgData = file_get_contents($tmpName);

            // A. 调用 Azure AI (自动修正路径拼接)
            $cleanEndpoint = rtrim(trim($endpoint), '/');
            $analyzeUrl = $cleanEndpoint . "/documentintelligence/documentModels/prebuilt-receipt:analyze?api-version=2023-07-31";

            $ch = curl_init($analyzeUrl);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Content-Type: application/octet-stream',
                'Ocp-Apim-Subscription-Key: ' . trim($apiKey)
            ]);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $imgData);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HEADER, true); // 必须获取头信息来拿 Operation-Location
            
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

            if ($httpCode !== 202) {
                $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
                $body = substr($response, $headerSize);
                throw new Exception("Azure 拒绝了请求。状态码: $httpCode。原因: " . $body);
            }

            // 提取结果查询地址
            preg_match('/Operation-Location: (.*)/i', $response, $matchesURL);
            $resultUrl = isset($matchesURL[1]) ? trim($matchesURL[1]) : null;
            curl_close($ch);

            if (!$resultUrl) throw new Exception("未能在响应头中找到 Operation-Location");

            // B. 轮询识别结果
            $isDone = false;
            $statusData = [];
            $retryCount = 0;
            while (!$isDone && $retryCount < 30) {
                sleep(2);
                $ch2 = curl_init($resultUrl);
                curl_setopt($ch2, CURLOPT_HTTPHEADER, ['Ocp-Apim-Subscription-Key: ' . trim($apiKey)]);
                curl_setopt($ch2, CURLOPT_RETURNTRANSFER, true);
                $jsonResponse = curl_exec($ch2);
                $statusData = json_decode($jsonResponse, true);
                
                if (isset($statusData['status']) && $statusData['status'] == 'succeeded') {
                    $isDone = true;
                } elseif (isset($statusData['status']) && $statusData['status'] == 'failed') {
                    throw new Exception("AI 解析图片失败。");
                }
                curl_close($ch2);
                $retryCount++;
            }

            // C. 写入 ocr.log (课题核心要求)
            file_put_contents($ocrLogFile, "--- SCAN [" . date('Y-m-d H:i:s') . "] ---\n" . $jsonResponse . "\n\n", FILE_APPEND);

            // D. 数据清洗与展示
            $doc = $statusData['analyzeResult']['documents'][0]['fields'] ?? [];
            $items = $doc['Items']['valueArray'] ?? [];
            $totalAmount = $doc['Total']['valueNumber'] ?? 0;

            foreach ($items as $item) {
                $rawName = $item['valueObject']['Description']['valueString'] ?? '不明商品';
                // 课题要求：去除“轻”、◎、空格、*
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
        fprintf($fp, chr(0xEF).chr(0xBB).chr(0xBF)); // 防止 Excel 乱码
        foreach ($displayResults as $row) {
            fputcsv($fp, [$row['name'], $row['price']]);
        }
        fclose($fp);

    } catch (Exception $e) {
        $errorMsg = $e->getMessage();
    }
}
?>

<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <title>全家收据 OCR 最终版</title>
    <style>
        body { font-family: "Microsoft YaHei", sans-serif; margin: 40px; line-height: 1.6; }
        .container { max-width: 800px; margin: auto; }
        .table { width: 100%; border-collapse: collapse; margin-top: 20px; }
        .table th, .table td { border: 1px solid #ddd; padding: 12px; text-align: left; }
        .error { background: #fff0f0; color: #d00; padding: 15px; border: 1px solid #d00; border-radius: 4px; }
        .btn { background: #0078d4; color: white; padding: 10px 20px; border: none; cursor: pointer; border-radius: 4px; }
        .total { font-weight: bold; background: #f9f9f9; }
    </style>
</head>
<body>
    <div class="container">
        <h1>全家收据识别系统</h1>

        <?php if ($errorMsg): ?>
            <div class="error">
                <strong>识别失败：</strong><br>
                <?= htmlspecialchars($errorMsg) ?>
            </div>
        <?php endif; ?>

        <form method="post" enctype="multipart/form-data">
            <p>请选择收据照片（支持多选）：</p>
            <input type="file" name="files[]" multiple accept="image/*">
            <br><br>
            <button type="submit" class="btn">开始上传并识别</button>
        </form>

        <?php if (!empty($displayResults)): ?>
            <h2>识别结果</h2>
            <table class="table">
                <thead><tr><th>商品名称</th><th>金额 (円)</th></tr></thead>
                <tbody>
                    <?php foreach ($displayResults as $res): ?>
                        <tr class="<?= $res['name'] == '合计' ? 'total' : '' ?>">
                            <td><?= htmlspecialchars($res['name']) ?></td>
                            <td><?= number_format($res['price']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <p>
                ✅ <a href="result.csv">下载结果 CSV</a> | 
                ✅ <a href="ocr.log" target="_blank">查看 ocr.log</a>
            </p>
        <?php endif; ?>
    </div>
</body>
</html>