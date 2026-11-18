<?php
declare(strict_types=1);
ini_set('display_errors','0');
header("Content-Security-Policy: default-src 'none'; style-src 'self'; form-action 'self'; frame-ancestors 'none'; base-uri 'none'");
header('Cache-Control: no-store');
header('Referrer-Policy: no-referrer');
header('X-Content-Type-Options: nosniff');
session_name('AceBilling');
session_start(['cookie_secure'=>true,'cookie_httponly'=>true,'cookie_samesite'=>'Lax','cookie_path'=>'/facturation/','use_strict_mode'=>true]);
$private = dirname(__DIR__,2).'/ace-billing';
$config = is_file("$private/config.php") ? require "$private/config.php" : [];
$sandbox = ($config['environment'] ?? '') === 'sandbox';
$action = $_GET['a'] ?? '';
function esc($value): string { return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE,'UTF-8'); }
function go(string $url): never { header("Location: $url",true,303); exit; }
function request(string $url,array $headers,?string $body=null): array {
    $ch=curl_init($url);
    curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>25,CURLOPT_HTTPHEADER=>$headers]);
    if ($body!==null) curl_setopt($ch,CURLOPT_POSTFIELDS,$body);
    $result=curl_exec($ch);
    $status=curl_getinfo($ch,CURLINFO_RESPONSE_CODE);
    if ($status<200 || $status>=300 || !is_string($result)) throw new RuntimeException("Service unavailable");
    return json_decode($result,true,512,JSON_THROW_ON_ERROR);
}
function token(array $fields): array {
    global $config;
    return request('https://oauth.platform.intuit.com/oauth2/v1/tokens/bearer',[
        'Authorization: Basic '.base64_encode($config['id'].':'.$config['secret']),
        'Accept: application/json','Content-Type: application/x-www-form-urlencoded'
    ],http_build_query($fields));
}
function companyToken(?array $initial=null): string {
    global $private,$config;
    $lock=fopen("$private/connection.lock",'c');
    if (!$lock || !flock($lock,LOCK_EX)) throw new RuntimeException();
    try {
        $file="$private/connection.json";
        $saved=$initial ? [] : json_decode(file_get_contents($file),true,512,JSON_THROW_ON_ERROR);
        if (!$initial && $saved['expires']>time()+90) return $saved['access_token'];
        $data=$initial ?? token(['grant_type'=>'refresh_token','refresh_token'=>$saved['refresh_token']]);
        if (empty($data['access_token']) || empty($data['refresh_token'])) throw new RuntimeException();
        $data['expires']=time()+(int)$data['expires_in'];
        $temp=tempnam($private,'connection-');
        if (!file_put_contents($temp,json_encode($data,JSON_THROW_ON_ERROR)) || !rename($temp,$file)) throw new RuntimeException();
        return $data['access_token'];
    } finally { fclose($lock); }
}
function api(string $path,?string $body=null): array {
    global $sandbox,$config;
    $host=$sandbox?'sandbox-quickbooks.api.intuit.com':'quickbooks.api.intuit.com';
    return request('https://'.$host.'/v3/company/'.rawurlencode($config['realm']).'/'.$path,[
        'Authorization: Bearer '.companyToken(),'Accept: application/json','Content-Type: application/json'
    ],$body);
}
function records(string $entity,int $page=1): array {
    $offset=1+($page-1)*50;
    $order=in_array($entity,['Invoice','Estimate'],true)?' ORDERBY TxnDate DESC':'';
    $result=api('query?query='.rawurlencode("SELECT * FROM $entity$order STARTPOSITION $offset MAXRESULTS 50"));
    return $result['QueryResponse'][$entity] ?? [];
}
function catalog(string $entity): array {
    $all=[];
    for ($page=1;;$page++) {
        $rows=records($entity,$page);
        foreach ($rows as $row) $all[$row['Id']]=$row;
        if (count($rows)<50) return $all;
    }
}
function id($value): string {
    if (!is_string($value) || !preg_match('/^[1-9][0-9]{0,19}$/D',$value)) throw new RuntimeException();
    return $value;
}
function formToken(): string {
    return '<input type="hidden" name="csrf" value="'.esc($_SESSION['csrf']).'">';
}
function money($amount): string { return number_format((float)$amount,2,'.',','); }
$staff=isset($_SESSION['email']) && in_array($_SESSION['email'],$config['employees'] ?? [],true) && ($_SESSION['until'] ?? 0)>time();
$error='';
ob_start();
try {
    if ($_SERVER['REQUEST_METHOD']==='POST' && (!isset($_SESSION['csrf']) || !hash_equals($_SESSION['csrf'],$_POST['csrf'] ?? ''))) throw new RuntimeException();
    if ($config && in_array($action,['login','connect'],true)) {
        $_SESSION['state']=bin2hex(random_bytes(32));
        $_SESSION['flow']=$action;
        go('https://appcenter.intuit.com/connect/oauth2?'.http_build_query(['client_id'=>$config['id'],'redirect_uri'=>$config['callback'],'response_type'=>'code','scope'=>$action==='connect'?'openid email com.intuit.quickbooks.accounting':'openid email','state'=>$_SESSION['state']]));
    }
    if ($config && $action==='callback') {
        $state=$_SESSION['state'] ?? null;
        $flow=$_SESSION['flow'] ?? '';
        unset($_SESSION['state'],$_SESSION['flow']);
        if (!$state || !hash_equals($state,$_GET['state'] ?? '')) throw new RuntimeException();
        $data=token(['grant_type'=>'authorization_code','code'=>$_GET['code'] ?? '','redirect_uri'=>$config['callback']]);
        $identity=request('https://'.($sandbox?'sandbox-':'').'accounts.platform.intuit.com/v1/openid_connect/userinfo',['Authorization: Bearer '.$data['access_token'],'Accept: application/json']);
        $email=strtolower($identity['email'] ?? '');
        if (!filter_var($identity['emailVerified'] ?? $identity['email_verified'] ?? false,FILTER_VALIDATE_BOOLEAN) || !in_array($email,$config['employees'],true)) throw new RuntimeException();
        if ($flow==='connect') {
            if ($email!==$config['admin'] || ($_GET['realmId'] ?? '')!==$config['realm']) throw new RuntimeException();
            companyToken($data);
        }
        session_regenerate_id(true);
        $_SESSION=['email'=>$email,'until'=>time()+3600,'csrf'=>bin2hex(random_bytes(32))];
        go('./');
    }
    if (!$staff) { ?>
<h1>Billing</h1><section><h2>View or pay your invoice</h2><p>Open the invoice email from Ace Electric and select the link to view your balance. Available payment methods appear on your invoice.</p><a href="/index.php/contact">Request a copy from Ace Electric</a></section>
<section><h2>Employee access</h2><a class="button" href="<?= $config?'?a=login':'https://qbo.intuit.com/' ?>">Sign in with QuickBooks</a></section>
<?php } else {
    $entity=($_GET['type'] ?? '')==='Estimate'?'Estimate':'Invoice';
    $kind=strtolower($entity);
    if ($_SERVER['REQUEST_METHOD']==='POST') {
        if ($action==='logout') { $_SESSION=[]; session_destroy(); go('./'); }
        $key=$_POST['key'] ?? '';
        if (!preg_match('/^[a-f0-9]{32}$/D',$key) || !isset($_SESSION['forms'][$key]) || $_SESSION['forms'][$key]!==[$action,$entity,$_GET['id'] ?? '']) throw new RuntimeException();
        if ($action==='send') {
            $docId=id($_GET['id'] ?? '');
            $doc=api("$kind/$docId")[$entity];
            if (!filter_var($doc['BillEmail']['Address'] ?? '',FILTER_VALIDATE_EMAIL)) throw new RuntimeException();
            api("$kind/$docId/send?requestid=$key",'');
        } elseif ($action==='create') {
            $customer=api('customer/'.id($_POST['customer'] ?? ''))['Customer'];
            $lines=[];
            foreach ($_POST['quantity'] ?? [] as $itemId=>$quantity) {
                if ($quantity==='' || $quantity==='0') continue;
                if (!preg_match('/^[1-9][0-9]{0,4}$/D',$quantity)) throw new RuntimeException();
                $item=api('item/'.id((string)$itemId))['Item'];
                if (!isset($item['UnitPrice']) || empty($item['Active'])) throw new RuntimeException();
                $detail=['ItemRef'=>['value'=>$item['Id']],'Qty'=>(int)$quantity,'UnitPrice'=>$item['UnitPrice']];
                $tax=$item['SalesTaxCodeRef'] ?? ($config['sales_tax_code'] ? ['value'=>$config['sales_tax_code']] : null);
                if (!$tax) throw new RuntimeException();
                $detail['TaxCodeRef']=$tax;
                $lines[]=['Amount'=>round($quantity*$item['UnitPrice'],2),'DetailType'=>'SalesItemLineDetail','SalesItemLineDetail'=>$detail];
            }
            if (!$lines) throw new RuntimeException();
            $body=['CustomerRef'=>['value'=>$customer['Id']],'Line'=>$lines];
            foreach (['PrimaryEmailAddr'=>'BillEmail','CurrencyRef'=>'CurrencyRef'] as $from=>$to) if (isset($customer[$from])) $body[$to]=$customer[$from];
            if ($kind==='invoice' && ($config['card_payments'] ?? false)) $body['AllowOnlineCreditCardPayment']=true;
            $doc=api("$kind?requestid=$key",json_encode($body,JSON_THROW_ON_ERROR))[$entity];
            $docId=id($doc['Id']);
        } else throw new RuntimeException();
        unset($_SESSION['forms'][$key]);
        go('?a=view&type='.$entity.'&id='.$docId);
    }
    ?><nav><a href="./">Invoices</a><a href="?type=Estimate">Estimates</a><a href="?a=clients">Clients</a><a href="?a=prices">Price list</a><form method="post" action="?a=logout"><?=formToken()?><button>Sign out</button></form></nav><?php
    if ($action==='new' || $action==='view') {
        $key=bin2hex(random_bytes(16));
        $_SESSION['forms']=array_slice($_SESSION['forms'] ?? [],-19,null,true);
        $_SESSION['forms'][$key]=[$action==='new'?'create':'send',$entity,$_GET['id'] ?? ''];
    }
    if ($action==='new') { ?>
<h1>New <?=esc($kind)?></h1><form method="post" action="?a=create&amp;type=<?=$entity?>"><?=formToken()?><input type="hidden" name="key" value="<?=$key?>"><label>Client <select name="customer" required><option value="">Choose a client</option><?php foreach (catalog('Customer') as $customer) { ?><option value="<?=esc($customer['Id'])?>"><?=esc($customer['DisplayName'])?></option><?php } ?></select></label><h2>Services and quantities</h2><?php foreach (catalog('Item') as $item) { if (!isset($item['UnitPrice'])) continue; ?><label><?=esc($item['FullyQualifiedName'])?> · <?=money($item['UnitPrice'])?><input type="number" min="0" max="99999" name="quantity[<?=esc($item['Id'])?>]" placeholder="0"></label><?php } ?><button>Create <?=$kind?></button></form>
<?php } elseif ($action==='view') {
        $docId=id($_GET['id'] ?? '');
        $doc=api("$kind/$docId")[$entity]; ?>
<h1><?=$entity?> <?=esc($doc['DocNumber'] ?? $docId)?></h1><p><?=esc($doc['CustomerRef']['name'])?> · <?=esc($doc['TxnDate'])?></p><ul><?php foreach ($doc['Line'] as $line) { if ($line['DetailType']!=='SalesItemLineDetail') continue; ?><li><?=esc($line['Description'] ?? $line['SalesItemLineDetail']['ItemRef']['name'] ?? '')?> · <?=money($line['Amount'])?></li><?php } ?></ul><p>Total <?=money($doc['TotalAmt'])?> <?=esc($doc['CurrencyRef']['value'] ?? '')?></p><?php if (isset($doc['Balance'])) { ?><p>Balance <?=money($doc['Balance'])?></p><?php } if (!empty($doc['BillEmail']['Address'])) { ?><form method="post" action="?a=send&amp;type=<?=$entity?>&amp;id=<?=$docId?>"><?=formToken()?><input type="hidden" name="key" value="<?=$key?>"><button>Send to <?=esc($doc['BillEmail']['Address'])?></button></form><?php }
    } else {
        $list=match($action){'clients'=>'Customer','prices'=>'Item',default=>$entity};
        $page=max(1,min(100000,(int)($_GET['page'] ?? 1)));
        $rows=records($list,$page);
        ?><h1><?=esc(match($list){'Customer'=>'Clients','Item'=>'Price list',default=>$entity.'s'})?></h1><?php if ($list===$entity) { ?><a class="button" href="?a=new&amp;type=<?=$entity?>">New <?=$kind?></a><?php } ?><ul><?php foreach ($rows as $row) { ?><li><?php if ($list==='Customer') echo esc($row['DisplayName']); elseif ($list==='Item') echo esc($row['FullyQualifiedName']).' · '.money($row['UnitPrice'] ?? 0); else { ?><a href="?a=view&amp;type=<?=$entity?>&amp;id=<?=esc($row['Id'])?>"><?=esc($row['DocNumber'] ?? $row['Id'])?> · <?=esc($row['CustomerRef']['name'] ?? '')?></a> · <?=esc($row['TxnDate'])?> · <?=money($row['TotalAmt'])?><?php } ?></li><?php } ?></ul><?php if (!$rows) echo '<p>No records on this page.</p>';
        foreach (['Previous'=>$page-1,'Next'=>$page+1] as $label=>$target) if ($target>0 && ($target<$page || count($rows)===50)) echo '<a href="?'.esc(http_build_query(['a'=>$action,'type'=>$entity,'page'=>$target])).'">'.$label.'</a> ';
    }
}
} catch (Throwable $e) {
    ob_clean();
    http_response_code(400);
    echo '<h1>Billing request unavailable</h1><p>Check your access and the information entered. If you submitted a document, check its history before trying again.</p><a href="./">Return to billing</a>';
}
$content=ob_get_clean();
?><!doctype html><html lang="en"><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Billing | Ace Electric Inc.</title><link rel="stylesheet" href="billing.css"><header><a href="/">Ace Electric Inc.</a></header><main><?=$content?></main></html>
