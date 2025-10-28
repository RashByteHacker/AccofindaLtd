<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <link rel="icon" type="image/jpeg" href="../Images/AccofindaLogo1.jpg">
    <title>Forgot Password</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <style>
        * {
            box-sizing: border-box;
        }

        body {
            font-family: 'Segoe UI', Arial, sans-serif;
            margin: 0;
            padding: 0;
            background: #f7f7f7;
            display: flex;
            align-items: center;
            justify-content: center;
            min-height: 100vh;
        }

        .box {
            width: 100%;
            max-width: 400px;
            background: #ffffff;
            padding: 30px;
            border-radius: 12px;
            box-shadow: 0 8px 20px rgba(0, 0, 0, 0.1);
        }

        h2 {
            text-align: center;
            margin-bottom: 20px;
            font-weight: normal;
        }

        input[type=email],
        button {
            width: 100%;
            padding: 12px;
            margin-top: 12px;
            border-radius: 8px;
            font-size: 15px;
        }

        input[type=email] {
            border: 1px solid #ccc;
            background: #f9f9f9;
        }

        button {
            background: #2c3e50;
            color: white;
            border: none;
            font-weight: bold;
            cursor: pointer;
        }

        button:hover {
            background: #1e2e3f;
        }

        @media (max-width: 480px) {
            .box {
                margin: 0 15px;
                padding: 25px 20px;
            }
        }
    </style>
</head>

<body>

    <div class="box">
        <h2>🔐 Forgot Password</h2>
        <form method="POST" action="send_reset_link">
            <input type="email" name="email" placeholder="Enter your email" required />
            <button type="submit">Send Reset Link</button>
        </form>
    </div>

</body>

</html>