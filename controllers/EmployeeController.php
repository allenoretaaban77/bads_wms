<?php

namespace app\controllers;

use Yii;
use yii\rest\Controller;
use yii\filters\auth\HttpBearerAuth;
use yii\filters\auth\QueryParamAuth;
use app\models\Employee;

/**
 * Employee API Controller
 * Handles employee authentication and API endpoints
 */
class EmployeeController extends Controller
{
    public $enableCsrfValidation = false;

    /**
     * {@inheritdoc}
     */
    public function behaviors()
    {
        $behaviors = parent::behaviors();

        $behaviors['authenticator'] = [
            'class' => HttpBearerAuth::class,
            'except' => ['login', 'register'],
        ];

        $behaviors['contentNegotiator'] = [
            'class' => \yii\filters\ContentNegotiator::class,
            'formats' => [
                'application/json' => \yii\web\Response::FORMAT_JSON,
            ],
        ];

        return $behaviors;
    }

    /**
     * Employee login - POST /api/employee/login
     * @return array
     */
    public function actionLogin()
    {
        if (Yii::$app->request->method !== 'POST') {
            Yii::$app->response->statusCode = 405;
            return ['error' => 'Method not allowed'];
        }

        $data = Yii::$app->request->post();
        $username = $data['username'] ?? null;
        $password = $data['password'] ?? null;

        if (!$username || !$password) {
            Yii::$app->response->statusCode = 400;
            return ['error' => 'Username and password are required'];
        }

        $employee = Employee::findByUsername($username);

        if (!$employee || !$employee->validatePassword($password)) {
            Yii::$app->response->statusCode = 401;
            return ['error' => 'Invalid username or password'];
        }

        if ($employee->status_id === Employee::STATUS_INACTIVE) {
            Yii::$app->response->statusCode = 403;
            return ['error' => 'Employee account is inactive'];
        }

        $accessToken = $this->generateToken();

        return [
            'success' => true,
            'id' => $employee->id,
            'employee_id' => $employee->employee_id,
            'employee_number' => $employee->employee_number,
            'firstname' => $employee->firstname,
            'lastname' => $employee->lastname,
            'surname' => $employee->surname,
            'username' => $employee->username,
            'position_name' => $employee->position_name,
            'position_id' => $employee->position_id,
            'status' => $employee->status,
            'status_id' => $employee->status_id,
            'access_token' => $accessToken,
            'date_created' => $employee->date_created,
            'date_updated' => $employee->date_updated,
        ];
    }

    /**
     * Employee registration - POST /api/employee/register
     * @return array
     */
    public function actionRegister()
    {
        if (Yii::$app->request->method !== 'POST') {
            Yii::$app->response->statusCode = 405;
            return ['error' => 'Method not allowed'];
        }

        $data = Yii::$app->request->post();

        $employee = new Employee();
        $employee->load($data, '');

        if (!$employee->validate()) {
            Yii::$app->response->statusCode = 422;
            return ['error' => 'Validation failed', 'errors' => $employee->errors];
        }

        if (!$employee->save()) {
            Yii::$app->response->statusCode = 500;
            return ['error' => 'Failed to create employee'];
        }

        Yii::$app->response->statusCode = 201;
        return [
            'success' => true,
            'id' => $employee->id,
            'employee_id' => $employee->employee_id,
            'employee_number' => $employee->employee_number,
            'firstname' => $employee->firstname,
            'lastname' => $employee->lastname,
            'surname' => $employee->surname,
            'username' => $employee->username,
            'position_name' => $employee->position_name,
            'position_id' => $employee->position_id,
            'status' => $employee->status,
            'status_id' => $employee->status_id,
            'date_created' => $employee->date_created,
            'date_updated' => $employee->date_updated,
        ];
    }

    /**
     * Get employee profile - GET /api/employee/profile
     * @return array
     */
    public function actionProfile()
    {
        if (Yii::$app->request->method !== 'GET') {
            Yii::$app->response->statusCode = 405;
            return ['error' => 'Method not allowed'];
        }

        $id = Yii::$app->request->get('id');

        if (!$id) {
            Yii::$app->response->statusCode = 400;
            return ['error' => 'Employee ID is required'];
        }

        $employee = Employee::findOne($id);

        if (!$employee) {
            Yii::$app->response->statusCode = 404;
            return ['error' => 'Employee not found'];
        }

        return [
            'success' => true,
            'id' => $employee->id,
            'employee_id' => $employee->employee_id,
            'employee_number' => $employee->employee_number,
            'firstname' => $employee->firstname,
            'lastname' => $employee->lastname,
            'surname' => $employee->surname,
            'username' => $employee->username,
            'position_name' => $employee->position_name,
            'position_id' => $employee->position_id,
            'status' => $employee->status,
            'status_id' => $employee->status_id,
            'date_created' => $employee->date_created,
            'date_updated' => $employee->date_updated,
        ];
    }

    /**
     * List all employees - GET /api/employee/list
     * @return array
     */
    public function actionList()
    {
        if (Yii::$app->request->method !== 'GET') {
            Yii::$app->response->statusCode = 405;
            return ['error' => 'Method not allowed'];
        }

        $query = Employee::find();

        // Filter by status
        $status = Yii::$app->request->get('status');
        if ($status !== null) {
            $query->where(['status_id' => $status]);
        }

        // Filter by position
        $positionId = Yii::$app->request->get('position_id');
        if ($positionId !== null) {
            $query->where(['position_id' => $positionId]);
        }

        $employees = $query->all();

        $result = [];
        foreach ($employees as $employee) {
            $result[] = [
                'id' => $employee->id,
                'employee_id' => $employee->employee_id,
                'employee_number' => $employee->employee_number,
                'firstname' => $employee->firstname,
                'lastname' => $employee->lastname,
                'surname' => $employee->surname,
                'username' => $employee->username,
                'position_name' => $employee->position_name,
                'position_id' => $employee->position_id,
                'status' => $employee->status,
                'status_id' => $employee->status_id,
                'date_created' => $employee->date_created,
                'date_updated' => $employee->date_updated,
            ];
        }

        return [
            'success' => true,
            'count' => count($result),
            'data' => $result,
        ];
    }

    /**
     * Update employee - PUT /api/employee/update
     * @return array
     */
    public function actionUpdate()
    {
        if (Yii::$app->request->method !== 'PUT') {
            Yii::$app->response->statusCode = 405;
            return ['error' => 'Method not allowed'];
        }

        $id = Yii::$app->request->getBodyParam('id');

        if (!$id) {
            Yii::$app->response->statusCode = 400;
            return ['error' => 'Employee ID is required'];
        }

        $employee = Employee::findOne($id);

        if (!$employee) {
            Yii::$app->response->statusCode = 404;
            return ['error' => 'Employee not found'];
        }

        $data = Yii::$app->request->getBodyParams();
        $employee->load($data, '');

        if (!$employee->validate()) {
            Yii::$app->response->statusCode = 422;
            return ['error' => 'Validation failed', 'errors' => $employee->errors];
        }

        if (!$employee->save()) {
            Yii::$app->response->statusCode = 500;
            return ['error' => 'Failed to update employee'];
        }

        return [
            'success' => true,
            'id' => $employee->id,
            'employee_id' => $employee->employee_id,
            'employee_number' => $employee->employee_number,
            'firstname' => $employee->firstname,
            'lastname' => $employee->lastname,
            'surname' => $employee->surname,
            'username' => $employee->username,
            'position_name' => $employee->position_name,
            'position_id' => $employee->position_id,
            'status' => $employee->status,
            'status_id' => $employee->status_id,
            'date_created' => $employee->date_created,
            'date_updated' => $employee->date_updated,
        ];
    }

    /**
     * Delete employee - DELETE /api/employee/delete
     * @return array
     */
    public function actionDelete()
    {
        if (Yii::$app->request->method !== 'DELETE') {
            Yii::$app->response->statusCode = 405;
            return ['error' => 'Method not allowed'];
        }

        $id = Yii::$app->request->getBodyParam('id');

        if (!$id) {
            Yii::$app->response->statusCode = 400;
            return ['error' => 'Employee ID is required'];
        }

        $employee = Employee::findOne($id);

        if (!$employee) {
            Yii::$app->response->statusCode = 404;
            return ['error' => 'Employee not found'];
        }

        if (!$employee->delete()) {
            Yii::$app->response->statusCode = 500;
            return ['error' => 'Failed to delete employee'];
        }

        return ['success' => true, 'message' => 'Employee deleted successfully'];
    }

    /**
     * Generate a random access token
     * @return string
     */
    private function generateToken()
    {
        return Yii::$app->security->generateRandomString(32);
    }
}
