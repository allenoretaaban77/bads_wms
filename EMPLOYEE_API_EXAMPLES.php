<?php
/**
 * Employee API Quick Start Examples
 * 
 * This file contains practical examples of how to use the Employee API
 */

// ========================================
// 1. SETUP: Create the database table
// ========================================
/*
    Run this command in your terminal:
    
    php yii migrate
    
    This will create the employees table with all necessary fields.
*/

// ========================================
// 2. REGISTER A NEW EMPLOYEE
// ========================================
/*
    POST /api/employee/register
    
    Request:
    {
        "employee_id": 100,
        "employee_number": "EMP001",
        "firstname": "John",
        "lastname": "Doe",
        "surname": "Smith",
        "username": "john.doe",
        "password": "SecurePassword123",
        "status": "Active",
        "status_id": 1,
        "position_name": "Manager",
        "position_id": 5
    }
*/

// Example with PHP/cURL:
/*
$ch = curl_init('http://localhost/api/employee/register');
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
    'employee_number' => 'EMP001',
    'firstname' => 'John',
    'lastname' => 'Doe',
    'username' => 'john.doe',
    'password' => 'SecurePassword123',
    'status_id' => 1,
    'position_name' => 'Manager',
    'position_id' => 5
]));
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

$response = curl_exec($ch);
curl_close($ch);

$data = json_decode($response, true);
// Store $data['access_token'] for authenticated requests
*/

// ========================================
// 3. LOGIN WITH USERNAME AND PASSWORD
// ========================================
/*
    POST /api/employee/login
    
    Request:
    {
        "username": "john.doe",
        "password": "SecurePassword123"
    }
    
    Response:
    {
        "success": true,
        "access_token": "random_token_string",
        "id": 1,
        "firstname": "John",
        "lastname": "Doe",
        ...
    }
*/

// Example with PHP/cURL:
/*
$ch = curl_init('http://localhost/api/employee/login');
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
    'username' => 'john.doe',
    'password' => 'SecurePassword123'
]));
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

$response = curl_exec($ch);
curl_close($ch);

$loginData = json_decode($response, true);
if ($loginData['success']) {
    $accessToken = $loginData['access_token'];
    // Use this token for authenticated requests
}
*/

// ========================================
// 4. GET EMPLOYEE PROFILE
// ========================================
/*
    GET /api/employee/profile?id=1
    
    Requires authentication token in header:
    Authorization: Bearer <access_token>
*/

// Example with PHP/cURL:
/*
$employeeId = 1;
$accessToken = 'your_access_token_here';

$ch = curl_init("http://localhost/api/employee/profile?id=" . $employeeId);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Content-Type: application/json',
    'Authorization: Bearer ' . $accessToken
]);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

$response = curl_exec($ch);
curl_close($ch);

$profile = json_decode($response, true);
*/

// ========================================
// 5. LIST ALL EMPLOYEES
// ========================================
/*
    GET /api/employee/list
    
    Optional filters:
    ?status=1 - Filter by status_id (1=Active)
    ?position_id=5 - Filter by position_id
    ?status=1&position_id=5 - Combine filters
*/

// Example with PHP/cURL:
/*
$accessToken = 'your_access_token_here';

$ch = curl_init('http://localhost/api/employee/list?status=1');
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Content-Type: application/json',
    'Authorization: Bearer ' . $accessToken
]);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

$response = curl_exec($ch);
curl_close($ch);

$employees = json_decode($response, true);
echo "Total employees: " . $employees['count'];
foreach ($employees['data'] as $emp) {
    echo $emp['firstname'] . " " . $emp['lastname'] . "\n";
}
*/

// ========================================
// 6. UPDATE EMPLOYEE
// ========================================
/*
    PUT /api/employee/update
    
    Request body must include 'id' field
    {
        "id": 1,
        "firstname": "Jane",
        "position_name": "Senior Manager"
    }
*/

// Example with PHP/cURL:
/*
$accessToken = 'your_access_token_here';

$ch = curl_init('http://localhost/api/employee/update');
curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PUT');
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
    'id' => 1,
    'firstname' => 'Jane',
    'position_name' => 'Senior Manager'
]));
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Content-Type: application/json',
    'Authorization: Bearer ' . $accessToken
]);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

$response = curl_exec($ch);
curl_close($ch);

$result = json_decode($response, true);
*/

// ========================================
// 7. DELETE EMPLOYEE
// ========================================
/*
    DELETE /api/employee/delete
    
    Request body:
    {
        "id": 1
    }
*/

// Example with PHP/cURL:
/*
$accessToken = 'your_access_token_here';

$ch = curl_init('http://localhost/api/employee/delete');
curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(['id' => 1]));
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Content-Type: application/json',
    'Authorization: Bearer ' . $accessToken
]);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

$response = curl_exec($ch);
curl_close($ch);

$result = json_decode($response, true);
*/

// ========================================
// TESTING WITH POSTMAN
// ========================================
/*
1. Register Endpoint:
   - URL: http://localhost/api/employee/register
   - Method: POST
   - Headers: Content-Type: application/json
   - Body (JSON):
     {
       "employee_number": "EMP001",
       "firstname": "John",
       "lastname": "Doe",
       "username": "john.doe",
       "password": "password123",
       "status_id": 1,
       "position_name": "Manager",
       "position_id": 5
     }

2. Login Endpoint:
   - URL: http://localhost/api/employee/login
   - Method: POST
   - Headers: Content-Type: application/json
   - Body (JSON):
     {
       "username": "john.doe",
       "password": "password123"
     }

3. Get Profile (with token):
   - URL: http://localhost/api/employee/profile?id=1
   - Method: GET
   - Headers: 
     - Content-Type: application/json
     - Authorization: Bearer {access_token_from_login}

4. List Employees (with token):
   - URL: http://localhost/api/employee/list
   - Method: GET
   - Headers:
     - Content-Type: application/json
     - Authorization: Bearer {access_token_from_login}

5. Update Employee (with token):
   - URL: http://localhost/api/employee/update
   - Method: PUT
   - Headers:
     - Content-Type: application/json
     - Authorization: Bearer {access_token_from_login}
   - Body (JSON):
     {
       "id": 1,
       "firstname": "Jane"
     }

6. Delete Employee (with token):
   - URL: http://localhost/api/employee/delete
   - Method: DELETE
   - Headers:
     - Content-Type: application/json
     - Authorization: Bearer {access_token_from_login}
   - Body (JSON):
     {
       "id": 1
     }
*/

// ========================================
// VALIDATION RULES
// ========================================
/*
Fields and their validation rules:

- employee_number: Required, String, Unique, Max 255 characters
- firstname: Required, String, Max 255 characters
- lastname: Required, String, Max 255 characters
- surname: String, Optional, Max 255 characters
- username: Required, String, Unique, Max 255 characters
- password: Required for registration, hashed on save
- status_id: Integer, Default 1 (Active)
- position_id: Integer, Optional
- position_name: String, Optional, Max 255 characters

Fields automatically managed:
- id: Auto-increment primary key
- hash: Generated from password
- date_created: Set on record creation
- date_updated: Updated on every save
*/

?>
