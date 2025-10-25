<?php
session_start();
// Define application version
if(!defined('APP_VERSION')) { define('APP_VERSION', '1.0.100.1'); }
$selectedService = isset($_GET['service']) ? $_GET['service'] : '';
include __DIR__ . '/db.php';

// Count booked slots for today to display "Full" in the dropdown (from DB)
$bookedCounts = [];
$sqlBooked = "SELECT `date`, `time`, COUNT(*) AS cnt
              FROM appointments
              WHERE LOWER(status) <> 'canceled'
              GROUP BY `date`, `time`";
$stmt = $conn->prepare($sqlBooked);
if ($stmt) {
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $key = $row['date'] . '|' . $row['time'];
            $bookedCounts[$key] = (int)$row['cnt'];
        }
    } else {
        // Fallback if mysqlnd get_result is unavailable
        $stmt->bind_result($rDate, $rTime, $rCnt);
        while ($stmt->fetch()) {
            $key = $rDate . '|' . $rTime;
            $bookedCounts[$key] = (int)$rCnt;
        }
    }
    $stmt->close();
} else {
    // Fallback if prepare() fails (e.g., SQL mode issues)
    if ($result = $conn->query($sqlBooked)) {
        while ($row = $result->fetch_assoc()) {
            $key = $row['date'] . '|' . $row['time'];
            $bookedCounts[$key] = (int)$row['cnt'];
        }
        $result->free();
    } else {
        error_log('Index booked-counts query failed: ' . $conn->error);
        // Leave $bookedCounts empty to avoid breaking the page
    }
}

// Get featured service (random)
$featuredServices = [
    ["title" => "Summer Special: AC Check", "description" => "Keep cool with our AC system inspection and recharge service.", "discount" => "15% OFF"],
    ["title" => "Road Trip Ready Package", "description" => "Complete vehicle inspection before your next adventure.", "discount" => "10% OFF"],
    ["title" => "New Customer Offer", "description" => "First-time customers receive special discounted service.", "discount" => "₱500 OFF"]
];
$featuredService = $featuredServices[array_rand($featuredServices)];
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Vehicle Maintenance & Service</title>
  <link rel="stylesheet" href="style.css">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
  <style>
    :root {
      --primary: #f0c040;
      --primary-dark: #d4a830;
      --dark: #222;
      --light: #f8f9fa;
      --gray: #6c757d;
      --success: #28a745;
      --danger: #dc3545;
      --warning: #ffc107;
    }
    
    body {
        background: url('33.png') no-repeat center center fixed;
        background-size: cover;
        font-family: 'Segoe UI', sans-serif;
        color: white;
        margin: 0;
        overflow-x: hidden;
    }
    
    /* Overlay for better text readability */
    body::before {
        content: "";
        position: fixed;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;
        background: rgba(0,0,0,0.5);
        z-index: -1;
    }
    
    /* Loading animation */
    .loading {
        position: fixed;
        top: 0;
        left: 0;
        right: 0;
        bottom: 0;
        background: rgba(0,0,0,0.85);
        z-index: 9999;
        display: flex;
        justify-content: center;
        align-items: center;
        flex-direction: column;
    }
    
    .loading-spinner {
        width: 50px;
        height: 50px;
        border: 5px solid rgba(240, 192, 64, 0.3);
        border-radius: 50%;
        border-top-color: var(--primary);
        animation: spin 1s ease-in-out infinite;
    }
    
    .loading p {
        margin-top: 15px;
        color: var(--primary);
        font-weight: 600;
    }
    
    @keyframes spin {
        to { transform: rotate(360deg); }
    }
    
    /* Header & Navigation */
    header {
        background: rgba(0,0,0,0.8);
        backdrop-filter: blur(10px);
        position: sticky;
        top: 0;
        z-index: 1000;
        padding: 10px 0;
        box-shadow: 0 2px 20px rgba(0,0,0,0.2);
        transition: all 0.3s ease;
    }
    
    header.scrolled {
        padding: 5px 0;
    }
    
    .logo img {
        height: 60px;
        transition: all 0.3s ease;
    }
    
    header.scrolled .logo img {
        height: 50px;
    }
    
    nav {
        display: flex;
        justify-content: space-between;
        align-items: center;
        max-width: 1200px;
        margin: 0 auto;
        padding: 0 20px;
    }
    
    .nav-links {
        display: flex;
        list-style: none;
        margin: 0;
        padding: 0;
        align-items: center;
    }
    
    .nav-links li {
        position: relative;
        margin: 0 5px;
    }
    
    .nav-links li a {
        color: white;
        text-decoration: none;
        padding: 10px 15px;
        display: block;
        font-weight: 500;
        transition: all 0.3s ease;
        border-radius: 5px;
    }
    
    .nav-links li a:hover {
        color: var(--primary);
        background: rgba(255,255,255,0.05);
    }
    
    /* Dropdown Menu */
    .dropdown {
        position: relative;
    }
    
    .dropdown-menu {
        position: absolute;
        top: 100%;
        left: 0;
        background: rgba(0,0,0,0.9);
        backdrop-filter: blur(10px);
        min-width: 200px;
        border-radius: 5px;
        padding: 10px 0;
        opacity: 0;
        visibility: hidden;
        transform: translateY(10px);
        transition: all 0.3s ease;
        box-shadow: 0 5px 15px rgba(0,0,0,0.2);
        z-index: 100;
    }
    
    .dropdown:hover .dropdown-menu {
        opacity: 1;
        visibility: visible;
        transform: translateY(0);
    }
    
    .dropdown-menu li a {
        padding: 10px 20px;
        color: white;
        transition: all 0.2s ease;
    }
    
    .dropdown-menu li a:hover {
        background: rgba(240,192,64,0.1);
        color: var(--primary);
        transform: translateX(5px);
    }
    
    /* Badge */
    .badge {
        background: var(--danger);
        color: white;
        font-size: 10px;
        font-weight: bold;
        padding: 2px 6px;
        border-radius: 50%;
        position: absolute;
        top: -5px;
        right: -5px;
        box-shadow: 0 2px 5px rgba(0,0,0,0.2);
    }
    
    /* Mobile Menu Button */
    .menu-btn {
        display: none;
        background: none;
        border: none;
        color: white;
        font-size: 24px;
        cursor: pointer;
        z-index: 101;
    }
    
    /* Hero Section */
    .hero {
        height: 90vh;
        display: flex;
        align-items: center;
        justify-content: center;
        text-align: center;
        position: relative;
        overflow: hidden;
    }
    
    .hero::after {
        content: "";
        position: absolute;
        bottom: 0;
        left: 0;
        width: 100%;
        height: 150px;
        background: linear-gradient(to top, rgba(0,0,0,0.8), transparent);
        z-index: 1;
    }
    
    .hero-content {
        max-width: 800px;
        padding: 0 20px;
        z-index: 2;
    }
    
    .hero-logo {
        width: 180px;
        height: auto;
        margin-bottom: 20px;
        animation: float 3s ease-in-out infinite;
    }
    
    @keyframes float {
        0%, 100% { transform: translateY(0); }
        50% { transform: translateY(-10px); }
    }
    
    .hero h1 {
        font-size: 3rem;
        margin-bottom: 20px;
        text-shadow: 2px 2px 5px rgba(0,0,0,0.5);
        animation: fadeInUp 1s ease-out;
    }
    
    .hero p {
        font-size: 1.2rem;
        margin-bottom: 30px;
        text-shadow: 1px 1px 3px rgba(0,0,0,0.5);
        animation: fadeInUp 1.2s ease-out;
    }
    
    @keyframes fadeInUp {
        from {
            opacity: 0;
            transform: translateY(20px);
        }
        to {
            opacity: 1;
            transform: translateY(0);
        }
    }
    
    .hero button, .cta-button {
        background: var(--primary);
        color: var(--dark);
        border: none;
        padding: 12px 25px;
        font-size: 1rem;
        font-weight: 600;
        border-radius: 30px;
        cursor: pointer;
        transition: all 0.3s ease;
        box-shadow: 0 4px 10px rgba(0,0,0,0.2);
        animation: fadeInUp 1.4s ease-out;
    }
    
    .hero button:hover, .cta-button:hover {
        background: var(--primary-dark);
        transform: translateY(-3px);
        box-shadow: 0 6px 15px rgba(0,0,0,0.3);
    }
    
    /* Featured Offer Banner */
    .featured-offer {
        background: linear-gradient(45deg, rgba(240,192,64,0.9), rgba(212,168,48,0.9));
        padding: 15px 20px;
        text-align: center;
        color: var(--dark);
        position: relative;
        overflow: hidden;
        animation: slideIn 1s ease-out;
    }
    
    @keyframes slideIn {
        from { transform: translateY(-100%); }
        to { transform: translateY(0); }
    }
    
    .featured-offer h3 {
        margin: 0;
        font-size: 1.2rem;
    }
    
    .featured-offer p {
        margin: 5px 0 0;
    }
    
    .featured-offer .discount-badge {
        position: absolute;
        top: 0;
        right: 0;
        background: var(--dark);
        color: white;
        padding: 5px 15px;
        transform: rotate(45deg) translate(20px, -15px);
        transform-origin: top right;
        font-weight: bold;
    }
    
    /* Services Section */
    .services-section {
        max-width: 1200px;
        margin: 50px auto;
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
        gap: 25px;
        padding: 0 20px;
    }
    
    .section-heading {
        grid-column: 1 / -1;
        text-align: center;
        margin-bottom: 20px;
        position: relative;
        padding-bottom: 15px;
    }
    
    .section-heading::after {
        content: "";
        position: absolute;
        bottom: 0;
        left: 50%;
        transform: translateX(-50%);
        width: 80px;
        height: 3px;
        background: var(--primary);
    }
    
    .service-card {
        background: rgba(0,0,0,0.8);
        border-radius: 15px;
        padding: 25px;
        text-align: center;
        transition: all 0.4s ease;
        box-shadow: 0 8px 20px rgba(0,0,0,0.3);
        border: 1px solid rgba(255,255,255,0.05);
        overflow: hidden;
        position: relative;
        z-index: 1;
        display: flex;
        flex-direction: column;
        height: 100%;
    }
    
    .service-card::before {
        content: "";
        position: absolute;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;
        background: linear-gradient(45deg, rgba(240,192,64,0.1), transparent);
        opacity: 0;
        transition: all 0.4s ease;
        z-index: -1;
    }
    
    .service-card:hover {
        transform: none !important;
        box-shadow: 0 15px 30px rgba(0,0,0,0.4);
    }
    
    .service-card:hover::before {
        opacity: 1;
    }
    
    .service-card img {
        width: 100%;
        height: 160px;
        object-fit: cover;
        border-radius: 10px;
        margin-bottom: 20px;
        transition: all 0.4s ease;
        box-shadow: 0 5px 15px rgba(0,0,0,0.2);
    }
    
    .service-card:hover img {
        transform: scale(1.05);
    }
    
    .service-card h3 {
        color: var(--primary);
        margin-bottom: 15px;
        font-size: 1.4rem;
        position: relative;
        padding-bottom: 10px;
    }
    
    .service-card h3::after {
        content: "";
        position: absolute;
        bottom: 0;
        left: 50%;
        transform: translateX(-50%);
        width: 40px;
        height: 2px;
        background: var(--primary);
        transition: all 0.4s ease;
    }
    
    .service-card:hover h3::after {
        width: 60px;
    }
    
    .service-card p {
        font-size: 0.95rem;
        margin-bottom: 20px;
        color: #ddd;
        line-height: 1.6;
        flex-grow: 1;
    }
    
    .service-buttons {
        display: flex;
        justify-content: center;
        gap: 10px;
        flex-wrap: wrap;
        position: relative;
        z-index: 10;
        margin-top: 15px;
    }
    
    .service-card a, .service-card button {
        flex: 1;
        width: 100%;
        display: inline-block;
        padding: 12px 20px;
        background: var(--primary);
        color: var(--dark);
        font-weight: 600;
        border-radius: 5px;
        text-decoration: none;
        border: none;
        cursor: pointer;
        transition: all 0.3s ease;
        text-transform: uppercase;
        font-size: 0.8rem;
        letter-spacing: 0.5px;
        text-align: center;
        position: relative;
        z-index: 20;
        display: block;
        pointer-events: auto !important;
    }
    
    .service-card a:hover, .service-card button:hover {
        background: var(--primary-dark);
        transform: translateY(-3px);
        box-shadow: 0 5px 10px rgba(0,0,0,0.2);
        border-color: rgba(255,255,255,0.2);
    }
    
    /* About Section */
    .about {
        background: rgba(0,0,0,0.8);
        padding: 60px 20px;
        text-align: center;
        position: relative;
        overflow: hidden;
    }
    
    .about::before {
        content: "";
        position: absolute;
        top: 0;
        left: 0;
        width: 100%;
        height: 5px;
        background: linear-gradient(to right, transparent, var(--primary), transparent);
    }
    
    .about h2 {
        color: var(--primary);
        margin-bottom: 30px;
        position: relative;
        display: inline-block;
        padding-bottom: 15px;
        font-size: 2.5rem;
    }
    
    .about h2::after {
        content: "";
        position: absolute;
        bottom: 0;
        left: 50%;
        transform: translateX(-50%);
        width: 60px;
        height: 3px;
        background: var(--primary);
    }
    
    .about p {
        max-width: 800px;
        margin: 0 auto;
        line-height: 1.8;
        font-size: 1.1rem;
    }
    
    /* Appointment Section */
    .appointment {
        background: rgba(0,0,0,0.8);
        padding: 60px 20px;
        margin-top: 50px;
        text-align: center; /* Add this line to center everything in the section */
    }
    
    .appointment h2 {
        text-align: center;
        color: var(--primary);
        margin-bottom: 20px;
        position: relative;
        display: inline-block;
        padding-bottom: 15px;
        font-size: 2.5rem;
        left: auto; /* Change from 50% to auto */
        transform: none; /* Remove the transform property */
        margin-left: auto; /* Add these auto margins to center */
        margin-right: auto;
    }
    
    .appointment h2::after {
        content: "";
        position: absolute;
        bottom: 0;
        left: 50%; /* Keep this at 50% */
        transform: translateX(-50%); /* Keep this transform */
        width: 60px;
        height: 3px;
        background: var(--primary);
    }
    
    .form-time {
        margin-top: 10px;
        padding: 12px;
        width: 100%;
        border-radius: 5px;
        border: 1px solid rgba(255,255,255,0.1);
        background: rgba(255,255,255,0.05);
        color: white;
        font-size: 1rem;
    }
    
    .appointment form {
        max-width: 600px;
        margin: 0 auto;
        background: rgba(0,0,0,0.5);
        padding: 30px;
        border-radius: 10px;
        border: 1px solid rgba(255,255,255,0.1);
        box-shadow: 0 10px 30px rgba(0,0,0,0.3);
    }
    
    .appointment input, 
    .appointment select {
        width: 100%;
        padding: 12px;
        margin-bottom: 15px;
        border-radius: 5px;
        border: 1px solid rgba(255,255,255,0.1);
        background: rgba(255,255,255,0.05);
        color: white;
        font-size: 1rem;
    }
    
    .appointment input:focus, 
    .appointment select:focus {
        outline: none;
        border-color: var(--primary);
        box-shadow: 0 0 0 2px rgba(240,192,64,0.3);
    }
    
    .appointment select option {
        background: #222;
        color: white;
    }
    
    .appointment button {
        width: 100%;
        padding: 15px;
        border: none;
        border-radius: 5px;
        background: var(--primary);
        color: var(--dark);
        font-size: 1.1rem;
        font-weight: 600;
        cursor: pointer;
        transition: all 0.3s ease;
        margin-top: 10px;
        text-transform: uppercase;
        letter-spacing: 1px;
    }
    
    .appointment button:hover {
        background: var(--primary-dark);
        transform: translateY(-3px);
        box-shadow: 0 5px 15px rgba(0,0,0,0.3);
    }
    
    option.full {
        background: var(--danger);
        color: white;
        font-weight: bold;
    }
    
    .book-desc {
        color: var(--primary);
        font-size: 1.2rem;
        text-align: center;
        margin-bottom: 30px;
        margin-top: 10px;
        font-weight: 600;
        text-shadow: 1px 1px 4px #000;
    }
    
    .note {
        color: var(--warning);
        margin: 15px 0;
        padding: 12px;
        background: rgba(255,193,7,0.1);
        border-left: 4px solid var(--warning);
        border-radius: 4px;
    }
    
    /* Footer */
    footer {
        background: rgba(0,0,0,0.9);
        text-align: center;
        padding: 30px 20px;
        margin-top: 50px;
        position: relative;
    }
    
    .version-badge {
        display:inline-block;
        background: linear-gradient(135deg,#444,#222);
        color:#f0c040;
        font-size:12px;
        font-weight:600;
        padding:6px 12px;
        border:1px solid rgba(240,192,64,0.4);
        border-radius:20px;
        letter-spacing:.5px;
        box-shadow:0 2px 6px rgba(0,0,0,0.4);
        margin-top:10px;
    }
    
    .version-watermark {
        position:absolute;
        top:4px; right:8px;
        font-size:10px;
        color:rgba(255,255,255,0.35);
        letter-spacing:1px;
        font-weight:500;
        pointer-events:none;
    }
    
    /* Back to Top Button */
    .back-to-top {
        position: fixed;
        bottom: 20px;
        right: 20px;
        width: 40px;
        height: 40px;
        background: var(--primary);
        color: var(--dark);
        border-radius: 50%;
        display: flex;
        justify-content: center;
        align-items: center;
        cursor: pointer;
        opacity: 0;
        visibility: hidden;
        transition: all 0.3s ease;
        z-index: 100;
        box-shadow: 0 2px 10px rgba(0,0,0,0.2);
    }
    
    .back-to-top.active {
        opacity: 1;
        visibility: visible;
    }
    
    .back-to-top:hover {
        background: var(--primary-dark);
        transform: translateY(-3px);
    }
    
    /* Utility Classes */
    .text-center { text-align: center; }
    .mb-30 { margin-bottom: 30px; }
    
    /* Responsive Styles */
    @media (max-width: 992px) {
        .nav-links {
            flex-wrap: wrap;
            justify-content: center;
        }
        
        .hero h1 {
            font-size: 2.5rem;
        }
    }
    
    @media (max-width: 768px) {
        .menu-btn {
            display: block;
        }
        
        nav {
            justify-content: space-between;
        }
        
        .nav-links {
            position: fixed;
            top: 0;
            right: -100%;
            width: 70%;
            height: 100vh;
            background: rgba(0,0,0,0.95);
            flex-direction: column;
            align-items: flex-start;
            padding: 80px 20px 30px;
            transition: all 0.4s ease;
            z-index: 100;
            overflow-y: auto;
        }
        
        .nav-links.active {
            right: 0;
        }
        
        .nav-links li {
            margin: 5px 0;
            width: 100%;
        }
        
        .dropdown-menu {
            position: static;
            opacity: 1;
            visibility: visible;
            transform: none;
            background: transparent;
            box-shadow: none;
            padding: 0 0 0 20px;
            display: none;
        }
        
        .dropdown.active .dropdown-menu {
            display: block;
        }
        
        .dropdown > a {
            display: flex !important;
            justify-content: space-between;
            align-items: center;
        }
        
        .dropdown > a::after {
            content: "\f107";
            font-family: "Font Awesome 6 Free";
            font-weight: 900;
            transition: all 0.3s ease;
        }
        
        .dropdown.active > a::after {
            transform: rotate(180deg);
        }
        
        .hero {
            height: 80vh;
        }
        
        .hero h1 {
            font-size: 2rem;
        }
        
        .hero p {
            font-size: 1rem;
        }
        
        .hero-logo {
            width: 140px;
        }
        
        .service-card {
            margin-bottom: 20px;
        }
    }
    
    @media (max-width: 576px) {
        .hero {
            height: 70vh;
        }
        
        .hero h1 {
            font-size: 1.8rem;
        }
        
        .hero-logo {
            width: 120px;
        }
        
        .featured-offer h3 {
            font-size: 1rem;
        }
        
        .featured-offer p {
            font-size: 0.85rem;
        }
        
        .service-buttons {
            flex-direction: column;
        }
        
        .about h2, 
        .appointment h2 {
            font-size: 2rem;
        }
        
        .appointment form {
            padding: 20px;
        }
    }
    
    /* Animation for Service Cards */
    @keyframes fadeInUp {
        from {
            opacity: 0;
            transform: translateY(30px);
        }
        to {
            opacity: 1;
            transform: translateY(0);
        }
    }
    
    .service-card {
        opacity: 0;
        animation: fadeInUp 0.8s ease forwards;
    }
    
    .service-card:nth-child(2) { animation-delay: 0.2s; }
    .service-card:nth-child(3) { animation-delay: 0.3s; }
    .service-card:nth-child(4) { animation-delay: 0.4s; }
    .service-card:nth-child(5) { animation-delay: 0.5s; }
    .service-card:nth-child(6) { animation-delay: 0.6s; }
    .service-card:nth-child(7) { animation-delay: 0.7s; }
    .service-card:nth-child(8) { animation-delay: 0.8s; }
    .service-card:nth-child(9) { animation-delay: 0.9s; }
  </style>
  <script>
    function bookService(service) {
        window.location.href = "index.php?service=" + encodeURIComponent(service) + "#appointment";
    }

    const servicePrices = {
        "Aircon Cleaning": 1200,
        "Air Filter Replacement": 800,
        "Brake Service": 1500,
        "Check Engine": 1000,
        "Check Wiring": 900,
        "Oil Change": 1100,
        "PMS": 2000,
        "Wheel Alignment": 1300
    };

    function updatePrice() {
        const service = document.getElementById('service').value;
        const price = servicePrices[service] || 0;
        document.getElementById('servicePrice').innerHTML = "Reservation Fee: <span style='color:#f0c040;font-size:1.1rem'>₱" + price.toLocaleString() + "</span>";
    }

    document.addEventListener('DOMContentLoaded', function() {
        // Update price
        updatePrice();
        
        // Pre-select service if passed in URL
        const urlParams = new URLSearchParams(window.location.search);
        const serviceParam = urlParams.get('service');
        if (serviceParam) {
            const serviceSelect = document.getElementById('service');
            if (serviceSelect) {
                for (let i = 0; i < serviceSelect.options.length; i++) {
                    if (serviceSelect.options[i].value === serviceParam) {
                        serviceSelect.selectedIndex = i;
                        updatePrice();
                        break;
                    }
                }
            }
        }
        
        // Set minimum date for appointment to today
        const dateInput = document.querySelector('input[type="date"]');
        if (dateInput) {
            const today = new Date();
            const dd = String(today.getDate()).padStart(2, '0');
            const mm = String(today.getMonth() + 1).padStart(2, '0');
            const yyyy = today.getFullYear();
            const todayStr = yyyy + '-' + mm + '-' + dd;
            dateInput.min = todayStr;
        }
        
        // Mobile menu
        const menuBtn = document.querySelector('.menu-btn');
        const navLinks = document.querySelector('.nav-links');
        if (menuBtn && navLinks) {
            menuBtn.addEventListener('click', function() {
                navLinks.classList.toggle('active');
                menuBtn.classList.toggle('active');
            });
        }
        
        // Dropdown toggle
        const dropdowns = document.querySelectorAll('.dropdown');
        if (window.innerWidth <= 768) {
            dropdowns.forEach(dropdown => {
                const link = dropdown.querySelector('a');
                link.addEventListener('click', function(e) {
                    e.preventDefault();
                    dropdown.classList.toggle('active');
                });
            });
        }
        
        // Smooth scroll
        document.querySelectorAll('a[href^="#"]').forEach(anchor => {
            anchor.addEventListener('click', function(e) {
                if (this.getAttribute('href').length > 1) {
                    e.preventDefault();
                    const target = document.querySelector(this.getAttribute('href'));
                    if (target) {
                        window.scrollTo({
                            top: target.offsetTop - 70,
                            behavior: 'smooth'
                        });
                    }
                }
            });
        });
        
        // Back to top button
        const backToTop = document.querySelector('.back-to-top');
        if (backToTop) {
            window.addEventListener('scroll', () => {
                if (window.pageYOffset > 300) {
                    backToTop.classList.add('active');
                } else {
                    backToTop.classList.remove('active');
                }
            });
            
            backToTop.addEventListener('click', () => {
                window.scrollTo({
                    top: 0,
                    behavior: 'smooth'
                });
            });
        }
        
        // Header scroll effect
        const header = document.querySelector('header');
        if (header) {
            window.addEventListener('scroll', () => {
                if (window.pageYOffset > 100) {
                    header.classList.add('scrolled');
                } else {
                    header.classList.remove('scrolled');
                }
            });
        }
        
        // Make sure service card links are clickable
        const serviceLinks = document.querySelectorAll('.service-card a');
        serviceLinks.forEach(link => {
            link.addEventListener('click', function(e) {
                // Prevent any parent elements from capturing the click
                e.stopPropagation();
                // Navigate to the href
                window.location.href = this.getAttribute('href');
            });
        });
        
        // Make entire card clickable if desired
        const serviceCards = document.querySelectorAll('.service-card');
        serviceCards.forEach(card => {
            const link = card.querySelector('a');
            if (link) {
                // Optional: Make the whole card clickable
                // Uncomment the following if you want the entire card to be clickable
                /*
                card.style.cursor = 'pointer';
                card.addEventListener('click', function() {
                    window.location.href = link.getAttribute('href');
                });
                */
            }
        });
    });
  </script>
</head>
<body>
    <!-- Remove Loading Screen -->
    <!--
    <div class="loading">
        <div class="loading-spinner"></div>
        <p>Loading...</p>
    </div>
    -->

    <!-- Back to Top Button -->
    <div class="back-to-top">
        <i class="fas fa-arrow-up"></i>
    </div>

    <!-- Featured Offer Banner (Optional) -->
    <div class="featured-offer">
        <span class="discount-badge"><?= htmlspecialchars($featuredService['discount']) ?></span>
        <h3><?= htmlspecialchars($featuredService['title']) ?></h3>
        <p><?= htmlspecialchars($featuredService['description']) ?></p>
    </div>

    <header>
        <div class="version-watermark">v<?= APP_VERSION ?></div>
        <nav>
            <div class="logo">
                <a href="index.php"><img src="22.png" alt="Logo" style="cursor:pointer;"></a>
            </div>
            <button class="menu-btn">
                <i class="fas fa-bars"></i>
            </button>
            <ul class="nav-links">
                <li><a href="#home">Home</a></li>
                <li class="dropdown">
                    <a href="#services">Services ▾</a>
                    <ul class="dropdown-menu">
                        <li><a href="pms.php">PMS</a></li>
                        <li><a href="brake.php">Brake</a></li>
                        <li><a href="oilchange.php">Oil Change</a></li>
                        <li><a href="checkengine.php">Check Engine</a></li>
                        <li><a href="wheelalignment.php">Wheel Alignment</a></li>
                        <li><a href="airfilter.php">Air Filter</a></li>
                        <li><a href="aircon.php">Aircon</a></li>
                        <li><a href="checkwiring.php">Check Wiring</a></li>
                    </ul>
                </li>
                <?php
                if(isset($_SESSION['user'])) {
                    $userId = $_SESSION['user']['id'];
                    // Pending appointments for My Appointment badge
                    $pendingCount = 0;
                    $stmt = $conn->prepare("SELECT COUNT(*) FROM appointments WHERE user_id = ? AND status = 'pending'");
                    $stmt->bind_param("i", $userId);
                    $stmt->execute();
                    $stmt->bind_result($pendingCount);
                    $stmt->fetch();
                    $stmt->close();

                    // Get notification count (unread notifications)
                    $notificationCount = 0;
                    $stmt = $conn->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = FALSE");
                    $stmt->bind_param("i", $userId);
                    $stmt->execute();
                    $stmt->bind_result($notificationCount);
                    $stmt->fetch();
                    $stmt->close();
                ?>
                    <li style="position:relative;">
                        <a href="myappointments.php" style="display:inline-flex;align-items:center;">
                            <i class="fas fa-calendar-check me-2" style="margin-right:5px;"></i> My Appointment
                            <?php if($pendingCount > 0): ?>
                                <span class="badge">
                                    <?= $pendingCount ?>
                                </span>
                            <?php endif; ?>
                        </a>
                    </li>
                    <li style="position:relative;">
                        <a href="notifications.php" style="display:inline-flex;align-items:center;">
                            <i class="fas fa-bell me-2" style="margin-right:5px; color:#f0c040;"></i> Notifications
                            <?php if($notificationCount > 0): ?>
                                <span class="badge">
                                    <?= $notificationCount ?>
                                </span>
                            <?php endif; ?>
                        </a>
                    </li>
                    <li><a href="vehicle_monitoring.php"><i class="fas fa-chart-line me-2" style="margin-right:5px;"></i> Vehicle Monitor</a></li>
                    <li><a href="myprofile.php"><i class="fas fa-user me-2" style="margin-right:5px;"></i> <?php echo htmlspecialchars($_SESSION['user']['name']); ?></a></li>
                    <li><a href="logout.php" style="color:#ff6b6b;"><i class="fas fa-sign-out-alt me-2" style="margin-right:5px;"></i> Logout</a></li>
                <?php } else { ?>
                    <li><a href="login.php" style="display:flex;align-items:center;"><i class="fas fa-sign-in-alt me-2" style="margin-right:5px;"></i> Login</a></li>
                <?php } ?>
            </ul>
        </nav>
    </header>

    <section id="home" class="hero">
        <div class="hero-content">
            <img src="22.png" alt="Big Logo" class="hero-logo">
            <h1>Welcome to Our Vehicle Service</h1>
            <p>Reliable, Fast, and Professional Car Care</p>
            <button onclick="document.getElementById('appointment').scrollIntoView({behavior:'smooth'})">
                <i class="fas fa-calendar-plus" style="margin-right:8px;"></i> Book Now
            </button>
        </div>
    </section>

    <!-- Services cards -->
    <h2 class="section-heading">Our Services</h2>
    <section id="services" class="services-section">
        <!-- PMS -->
        <div class="service-card">
            <img src="images/pms.jpg" alt="PMS">
            <h3>PMS (PREVENTIVE MAINTENANCE SCHEDULE)</h3>
            <p>Preventive Maintenance Service keeps your car in peak condition, avoiding costly repairs.</p>
            <div class="service-buttons">
                <a href="pms.php" onclick="window.location.href='pms.php'; return true;">
                    <i class="fas fa-info-circle"></i> VIEW DETAILS
                </a>
            </div>
        </div>
        
        <!-- Brake -->
        <div class="service-card">
            <img src="images/brake.png" alt="Brake Service">
            <h3>Brake Check</h3>
            <p>Ensure safety with our brake inspection, replacement, and maintenance services.</p>
            <div class="service-buttons">
                <a href="brake.php" onclick="window.location.href='brake.php'; return true;">
                    <i class="fas fa-info-circle"></i> VIEW DETAILS
                </a>
            </div>
        </div>
        
        <!-- Oil Change -->
        <div class="service-card">
            <img src="images/oilchange.jpg" alt="Oil Change">
            <h3>Oil Change</h3>
            <p>Regular oil changes protect your engine, improve performance, and extend engine life.</p>
            <div class="service-buttons">
                <a href="oilchange.php" onclick="window.location.href='oilchange.php'; return true;">
                    <i class="fas fa-info-circle"></i> VIEW DETAILS
                </a>
            </div>
        </div>
        
        <!-- Check Engine -->
        <div class="service-card">
            <img src="images/checkengine.png" alt="Check Engine">
            <h3>Check Engine</h3>
            <p>Diagnostic checks to quickly identify and fix any issues your car may be facing.</p>
            <div class="service-buttons">
                <a href="checkengine.php" onclick="window.location.href='checkengine.php'; return true;">
                    <i class="fas fa-info-circle"></i> VIEW DETAILS
                </a>
            </div>
        </div>
        
        <!-- Wheel Alignment -->
        <div class="service-card">
            <img src="images/wheelalignment.png" alt="Wheel Alignment">
            <h3>Wheel Alignment</h3>
            <p>Proper alignment ensures a smoother ride, better handling, and extended tire life.</p>
            <div class="service-buttons">
                <a href="wheelalignment.php" onclick="window.location.href='wheelalignment.php'; return true;">
                    <i class="fas fa-info-circle"></i> VIEW DETAILS
                </a>
            </div>
        </div>
        
        <!-- Air Filter -->
        <div class="service-card">
            <img src="images/airfilter.png" alt="Air Filter">
            <h3>Air Filter</h3>
            <p>Clean air filters improve engine efficiency and help keep your engine healthy.</p>
            <div class="service-buttons">
                <a href="airfilter.php" onclick="window.location.href='airfilter.php'; return true;">
                    <i class="fas fa-info-circle"></i> VIEW DETAILS
                </a>
            </div>
        </div>
        
        <!-- Aircon -->
        <div class="service-card">
            <img src="images/aircon.png" alt="Aircon">
            <h3>Aircon</h3>
            <p>Maintain cool comfort with professional AC cleaning, repair, and maintenance.</p>
            <div class="service-buttons">
                <a href="aircon.php" onclick="window.location.href='aircon.php'; return true;">
                    <i class="fas fa-info-circle"></i> VIEW DETAILS
                </a>
            </div>
        </div>
        
        <!-- Wiring -->
        <div class="service-card">
            <img src="images/wiring.png" alt="Check Wiring">
            <h3>Check Wiring</h3>
            <p>Ensure your vehicle's electrical system is safe and fully functional with professional wiring inspection.</p>
            <div class="service-buttons">
                <a href="checkwiring.php" onclick="window.location.href='checkwiring.php'; return true;">
                    <i class="fas fa-info-circle"></i> VIEW DETAILS
                </a>
            </div>
        </div>
    </section>

    <section id="about" class="about">
        <h2>About Us</h2>
        <p>
            We are a professional vehicle maintenance and repair center offering complete
            automotive solutions. Our expert technicians ensure your car stays in
            top shape, providing quality service and genuine parts.
        </p>
    </section>

    <section id="appointment" class="appointment">
        <h2>Book Appointment</h2>
        <div class="book-desc">Book our services and keep your vehicle in top condition!</div>
        <?php
        if (isset($_GET['error']) && $_GET['error'] === 'slot_taken') {
            echo "<p class='note' style='color:red;'><i class='fas fa-exclamation-triangle' style='margin-right:8px;'></i> This time slot is already fully booked.</p>";
        } elseif (isset($_GET['success'])) {
            echo "<p class='note' style='color:lime;'><i class='fas fa-check-circle' style='margin-right:8px;'></i> Booking successful! Awaiting approval.</p>";
        }

        if (!isset($_SESSION['user'])) {
            echo "<p class='note'><i class='fas fa-exclamation-triangle' style='margin-right:8px;'></i> Please <a href='login.php' style='color:#f0c040;'>log in</a> to book an appointment.</p>";
        } else {
            $userId = $_SESSION['user']['id'];
            $userCars = [];
            $stmt = $conn->prepare("SELECT car_model, plate_number FROM cars WHERE user_id = ?");
            $stmt->bind_param("i", $userId);
            $stmt->execute();
            $result = $stmt->get_result();
            while ($row = $result->fetch_assoc()) {
                $userCars[] = $row['car_model'] . " (" . $row['plate_number'] . ")";
            }
            $stmt->close();

            if (count($userCars) === 0) {
                echo "<p class='note'><i class='fas fa-exclamation-triangle' style='margin-right:8px;'></i> You have no registered car. <a href='myprofile.php' style='color:#f0c040;'>Register your car first</a> to enable booking.</p>";
            } else {
                echo '<form method="POST" action="book_appointment.php">';
                echo '<div style="background:rgba(255,193,7,0.1);color:#ffc107;padding:15px;border-radius:8px;margin-bottom:20px;border:1px solid rgba(255,193,7,0.3);text-align:center;font-size:15px;">';
                echo '<i class="fas fa-info-circle" style="margin-right:8px;"></i> <strong>Note:</strong> The time you select depends on mechanic availability. We will notify you if your chosen time has a mechanic available.';
                echo '</div>';
                echo '<div style="position:relative;">';
                echo '<i class="fas fa-user" style="position:absolute;left:15px;top:12px;color:#f0c040;"></i>';
                echo '<input type="text" name="name" placeholder="Your Name" value="'.htmlspecialchars($_SESSION['user']['name']).'" required style="padding-left:40px;">';
                echo '</div>';
                
                echo '<div style="position:relative;">';
                echo '<i class="fas fa-calendar-alt" style="position:absolute;left:15px;top:12px;color:#f0c040;"></i>';
                echo '<input type="date" name="date" required style="padding-left:40px;">';
                echo '</div>';

                $timeSlots = ["08:00 AM","09:00 AM","10:00 AM","01:00 PM","02:00 PM","03:00 PM"];
                echo '<div style="position:relative;">';
                echo '<i class="fas fa-clock" style="position:absolute;left:15px;top:20px;color:#f0c040;"></i>';
                echo '<select name="time" class="form-time" required style="padding-left:40px;">';
                echo '<option value="">Select Preferred Time</option>';
                foreach ($timeSlots as $time) {
                    $key = date("Y-m-d")."|".$time;
                    if (isset($bookedCounts[$key]) && $bookedCounts[$key] >= 5) {
                        echo '<option value="'.$time.'" class="full" disabled>'.$time.' (Full)</option>';
                    } else {
                        echo '<option value="'.$time.'">'.$time.'</option>';
                    }
                }
                echo '</select>';
                echo '</div>';

                echo '<div style="position:relative;">';
                echo '<i class="fas fa-car" style="position:absolute;left:15px;top:20px;color:#f0c040;"></i>';
                echo '<select name="car" required style="padding-left:40px;">';
                echo '<option value="">Select Your Car</option>';
                foreach ($userCars as $car) {
                    echo '<option value="'.htmlspecialchars($car).'">'.htmlspecialchars($car).'</option>';
                }
                echo '</select>';
                echo '</div>';

                     echo '<div style="position:relative;">';
                     echo '<i class="fas fa-tools" style="position:absolute;left:15px;top:20px;color:#f0c040;"></i>';
                     echo '<select id="service" name="service" onchange="updatePrice()" required style="padding-left:40px;">';
                echo '<option value="">Select Service</option>';
                echo '<option value="Aircon Cleaning">Aircon Cleaning</option>';
                echo '<option value="Air Filter Replacement">Air Filter Replacement</option>';
                echo '<option value="Brake Service">Brake Service</option>';
                echo '<option value="Check Engine">Check Engine</option>';
                echo '<option value="Check Wiring">Check Wiring</option>';
                echo '<option value="Oil Change">Oil Change</option>';
                echo '<option value="PMS">PMS</option>';
                echo '<option value="Wheel Alignment">Wheel Alignment</option>';
                echo '</select>';
                echo '</div>';

                     // Odometer (optional)
                     echo '<div style="position:relative;">';
                     echo '<i class="fas fa-tachometer-alt" style="position:absolute;left:15px;top:14px;color:#f0c040;"></i>';
                     echo '<input type="number" name="odometer" min="0" step="1" placeholder="Odometer (km) - optional" '
                         . 'style="padding:12px 12px 12px 40px; width:100%; box-sizing:border-box; border-radius:8px; '
                         . 'border:1px solid rgba(255,255,255,0.1); background:rgba(0,0,0,0.6); color:#fff; outline:none;">';
                     echo '</div>';

                // Comments (optional)
                echo '<div style="position:relative;">';
                echo '<i class="fas fa-comment-dots" style="position:absolute;left:15px;top:14px;color:#f0c040;"></i>';
                echo '<textarea name="comments" rows="3" maxlength="500" placeholder="Comments for the mechanic (optional)" style="padding:12px 12px 12px 40px; width:100%; box-sizing:border-box; border-radius:8px; border:1px solid rgba(255,255,255,0.1); background:rgba(0,0,0,0.6); color:#fff; outline:none;"></textarea>';
                echo '</div>';

                echo '<div id="servicePrice" style="margin:20px 0; font-weight:bold; text-align:center; padding:10px; background:rgba(240,192,64,0.1); border-radius:5px;">Reservation Fee: <span style="color:#f0c040;font-size:1.1rem">₱0</span></div>';

                echo '<button type="submit"><i class="fas fa-calendar-check" style="margin-right:8px;"></i> Submit Appointment</button>';
                echo '</form>';
            }
        }
        ?>
    </section>

    <footer>
        <p>© <?= date('Y') ?> Vehicle Service Center | All Rights Reserved</p>
        <div class="version-badge"><i class="fas fa-code-branch" style="margin-right:6px;"></i>Version <?= APP_VERSION ?></div>
    </footer>

</body>
</html>