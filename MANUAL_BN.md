# MyStack ফ্রেমওয়ার্ক - পূর্ণাঙ্গ ইউজার ম্যানুয়াল

**MyStack**-এর অফিসিয়াল ডকুমেন্টেশনে আপনাকে স্বাগতম! এটি একটি জিরো-ডিপেন্ডেন্সি, সিঙ্গেল-ক্লাস ওরিয়েন্টেড পিএইচপি ফ্রেমওয়ার্ক। এই ম্যানুয়ালটি মানুষের পাশাপাশি এআই (AI) অ্যাসিস্ট্যান্টদের জন্য একটি পূর্ণাঙ্গ গাইডলাইন হিসেবে তৈরি করা হয়েছে, যাতে তারা এই ইকোসিস্টেমটি সম্পূর্ণভাবে বুঝতে ও ব্যবহার করতে পারে।

---

## সূচিপত্র
1. [পরিচিতি ও আর্কিটেকচার](#1-পরিচিতি-ও-আর্কিটেকচার)
2. [কমান্ড-লাইন টুল (`mystack`)](#2-কমান্ড-লাইন-টুল-mystack)
3. [রাউটিং এবং সিকিউরিটি (`PHRO`)](#3-রাউটিং-এবং-সিকিউরিটি-phro)
4. [ডাটাবেজ এবং ওআরএম (`PHDB`)](#4-ডাটাবেজ-এবং-ওআরএম-phdb)
5. [ইউজার ইন্টারফেস (`PHUI` ও `PHML`)](#5-ইউজার-ইন্টারফেস-phui-ও-phml)
6. [সিএসএস জেনারেটর (`PHCS`)](#6-সিএসএস-জেনারেটর-phcs)
7. [জাভাস্ক্রিপ্ট ব্রিজ (`PHJS`)](#7-জাভাস্ক্রিপ্ট-ব্রিজ-phjs)
8. [পেমেন্ট আর্কিটেকচার (`PHPA`)](#8-পেমেন্ট-আর্কিটেকচার-phpa)
9. [অন্যান্য কোর লাইব্রেরি (`PHEM`, `PHAU`, `PHEV`)](#9-অন্যান্য-কোর-লাইব্রেরি)

---

## 1. পরিচিতি ও আর্কিটেকচার
MyStack কোনো থার্ড-পার্টি প্যাকেজের (যেমন: Composer বা NPM) উপর নির্ভরশীল নয়। এটি ফাইল ইম্পোর্ট করার জন্য নিজস্ব ডাইনামিক ইম্পোর্টার ব্যবহার করে।

### ডিরেক্টরি স্ট্রাকচার
- **`/library/`**: ফ্রেমওয়ার্কের মূল কম্পোনেন্টগুলো এখানে থাকে (এগুলো পরিবর্তন করা নিষেধ)।
- **`/app/`**: সমস্ত ব্যাকএন্ড লজিক (Controllers, Models, Middleware) এখানে থাকে। এখানে কোনো সাব-ফোল্ডার তৈরি করা যাবে না।
- **`/component/`**: ফ্রন্টএন্ড UI এলিমেন্ট এবং ভিউগুলো (Views) এখানে থাকে। এখানে কোনো সাব-ফোল্ডার তৈরি করা যাবে না।
- **`/src/`**: স্ট্যাটিক ফাইল যেমন JS, CSS এবং ক্যাশ ফাইল এখানে থাকে।

### ডাইনামিক ইম্পোর্টার
সাধারণ `require` বা `include` ব্যবহার না করে গ্লোবাল `import()` ফাংশনটি ব্যবহার করতে হবে।
```php
// একটি ব্যাকএন্ড কন্ট্রোলার ইম্পোর্ট করতে
import('app:UserController');

// একটি ফ্রন্টএন্ড ভিউ ইম্পোর্ট করতে
import('component:dashboard');
```

---

## 2. কমান্ড-লাইন টুল (`mystack`)
`mystack` CLI টুল ব্যবহার করে খুব সহজেই কোড জেনারেট, সার্ভার স্টার্ট এবং কোড স্ট্রাকচার ফিক্স করা যায়।

### ব্যবহারের উদাহরণ:
- **সার্ভার স্টার্ট করতে**: `php mystack serve 8000`
- **স্টার্টার ডেমো জেনারেট করতে**: `php mystack get:started`
- **কন্ট্রোলার তৈরি করতে**: `php mystack make:controller User`
- **মডেল তৈরি করতে**: `php mystack make:model Product`
- **কম্পোনেন্ট তৈরি করতে**: `php mystack make:component Alert`
- **কোড ফিক্স করতে**: `php mystack doctor` (এটি স্বয়ংক্রিয়ভাবে সিনট্যাক্স, স্পেস এবং পারমিশন ঠিক করে দেয়)।

---

## 3. রাউটিং এবং সিকিউরিটি (`PHRO`)
`PHRO` হলো একটি শক্তিশালী রাউটার, যাতে বিল্ট-ইন WAF (Web Application Firewall) রয়েছে।

### সাধারণ রাউটিং
```php
PHRO::get('/', [HomeController::class, 'index']);
PHRO::post('/submit', [FormController::class, 'save']);
```

### মিডলওয়্যার এবং সিকিউরিটি
```php
// মিডলওয়্যার প্রয়োগ করতে
PHRO::add('GET', '/dashboard', [DashboardController::class, 'view'], ['AuthMiddleware']);

// বিল্ট-ইন ফায়ারওয়াল চালু করতে
PHRO::guard();
```

---

## 4. ডাটাবেজ এবং ওআরএম (`PHDB`)
`PHDB` প্রিপেয়ার্ড স্টেটমেন্ট ব্যবহার করে, যা যেকোনো ধরনের SQL injection থেকে ডাটাবেজকে নিরাপদ রাখে।

### কনফিগারেশন (`index.php` বা `.env` ফাইলে)
```php
PHDB::$host = 'localhost';
PHDB::$username = 'root';
PHDB::$password = 'secret';
PHDB::$dbname = 'mystack_db';
```

### ব্যবহারের উদাহরণ
```php
// ডাটা সিলেক্ট করা
$users = PHDB::query("SELECT * FROM users WHERE status = ?", ['active']);

// নতুন ডাটা ইনসার্ট করা
$userId = PHDB::query("INSERT INTO users (name, email) VALUES (?, ?)", ['John', 'john@example.com']);

// ডাটা আপডেট করা
PHDB::query("UPDATE users SET status = ? WHERE id = ?", ['inactive', 1]);
```

---

## 5. ইউজার ইন্টারফেস (`PHUI` ও `PHML`)
MyStack-এ Blade বা Twig ব্যবহার করা নিষেধ। রিউজেবল কম্পোনেন্ট তৈরি করতে `PHUI` ব্যবহার করতে হবে।

### `PHUI`-এর ব্যবহার
`/component/` ফোল্ডারে UI কম্পোনেন্ট তৈরি করে তা সহজেই রেন্ডার করা যায়:
```php
// একটি বাটন কম্পোনেন্ট রেন্ডার করা
echo PHUI::ui('html:button', [
    'text' => 'Click Me',
    'class' => 'bg-blue-500 text-white p-2 rounded'
]);
```

### `PHML` (PHP Markup Language)-এর ব্যবহার
বিশৃঙ্খল HTML কনক্যাটেনেশন এড়িয়ে সুন্দর DSL সিনট্যাক্সে HTML লিখতে `phml()` ব্যবহার করা হয়।
```php
echo phml(<<<DSL
div {
    class: "container mx-auto";
    h1 {
        class: "text-2xl font-bold";
        "Welcome to MyStack";
    }
}
DSL);
```

---

## 6. সিএসএস জেনারেটর (`PHCS`)
MyStack `PHCS` ব্যবহার করে অন-দ্য-ফ্লাই (রিয়েলটাইম) টেইলউইন্ডের মতো ইউটিলিটি CSS জেনারেট করে।

### ব্যবহার
যেকোনো কম্পোনেন্টে সরাসরি টেইলউইন্ড ইউটিলিটি ক্লাস লিখলেই কাজ হবে।
```html
<div class="bg-red-500 hover:bg-red-700 text-white font-bold py-2 px-4 rounded">
    Dynamic CSS Button
</div>
```
`PHCS` স্বয়ংক্রিয়ভাবে `bg-red-500` পার্স করে পেজের হেডারে সঠিক CSS বসিয়ে দেবে, এর জন্য কোনো Node.js বা বিল্ড স্টেপের প্রয়োজন নেই।

---

## 7. জাভাস্ক্রিপ্ট ব্রিজ (`PHJS`)
`PHJS` ন্যাচারাল ল্যাঙ্গুয়েজ প্রসেসিং (NLP) ব্যবহার করে খুব সহজে জাভাস্ক্রিপ্ট জেনারেট করে এবং এটি Alpine.js ও HTMX সাপোর্ট করে।

### ব্যবহারের উদাহরণ
```html
<!-- সাধারণ NLP ম্যাপিং -->
<button @click="<?= phjs('toast "Saved successfully!"') ?>">Save</button>

<!-- কমপ্লেক্স DOM ইন্টারঅ্যাকশন -->
<button @click="<?= phjs('hide #modal then show #successMessage') ?>">Confirm</button>
```

---

## 8. পেমেন্ট আর্কিটেকচার (`PHPA`)
`PHPA` হলো একটি বিশাল পেমেন্ট গেটওয়ে লাইব্রেরি, যা ৩০টিরও বেশি পেমেন্ট গেটওয়ে সাপোর্ট করে।

### সাধারণ কনফিগারেশন
```php
// Stripe কনফিগারেশন
PHPA::stripe()->setKeys('pk_test_123', 'sk_test_123');

// চার্জ করা
$response = PHPA::stripe()->charge(50.00, 'USD', 'ORDER_001');

if ($response['success']) {
    echo "Payment successful! Transaction ID: " . $response['transaction_id'];
}
```

### সাপোর্টেড গেটওয়ে (কয়েকটি)
- **ইন্টারন্যাশনাল**: Stripe, Paypal, Razorpay, Braintree
- **বাংলাদেশ (BD)**: Bkash, Nagad, SSLCommerz, Aamarpay
- **ক্রিপ্টো (Crypto)**: Binance, Coinbase, Coinpayments

---

## 9. অন্যান্য কোর লাইব্রেরি
- **`PHAU` (অথেনটিকেশন)**: সুরক্ষিত লগইন, সেশন এবং রেজিস্ট্রেশন ম্যানেজ করে।
- **`PHEM` (ইমেইল)**: ইমেইল পাঠানোর জন্য শক্তিশালী লাইব্রেরি (SMTP/IMAP/POP3)।
    ```php
    PHEM::smtp('smtp.example.com', 465, 'ssl');
    PHEM::smtpLogin('user@example.com', 'password');
    ```
- **`PHEV` (ইভেন্টস/ওয়েবসকেটস)**: রিয়েলটাইম ডাটা স্ট্রিমিং এবং সকেট কানেকশন পরিচালনা করে।

---
**ম্যানুয়ালের সমাপ্তি** - *MyStack-এর সাথে দ্রুতগামী, নিরাপদ এবং শক্তিশালী অ্যাপ্লিকেশন তৈরি করতে থাকুন!*
