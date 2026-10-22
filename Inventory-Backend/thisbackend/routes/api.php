<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

use App\Http\Controllers\AuthController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\DepartmentController;
use App\Http\Controllers\ItemController;
use App\Http\Controllers\UniformTypeController;
use App\Http\Controllers\UniformVariantController;
use App\Http\Controllers\UniformReservationController;
use App\Http\Controllers\RequestController;
use App\Http\Controllers\LogController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\BuildingController;
use App\Http\Controllers\RoomController;
use App\Http\Controllers\RoomEquipmentController;
use App\Http\Controllers\SettingController;
use App\Http\Controllers\RestockRequestController;
use App\Http\Controllers\EquipmentReportController;
use App\Http\Controllers\InspectionController;
use App\Http\Controllers\AccountRequestController;
use App\Http\Middleware\AdminSessionTimeoutMiddleware;


/*
|--------------------------------------------------------------------------
| Authentication
|--------------------------------------------------------------------------
*/

// Rate limited so the approval queue cannot be flooded.
Route::post('/register', [AuthController::class, 'register'])
    ->middleware('throttle:5,1');
// Rate limited (5 per minute per username + IP) against password guessing.
Route::post('/login', [AuthController::class, 'login'])
    ->middleware('throttle:login');

Route::post('/logout', [AuthController::class, 'logout'])
    ->middleware([AdminSessionTimeoutMiddleware::class, 'auth:sanctum']);


/*
|--------------------------------------------------------------------------
| Authenticated Users
|--------------------------------------------------------------------------
*/

Route::middleware([AdminSessionTimeoutMiddleware::class, 'auth:sanctum', \App\Http\Middleware\ForcePasswordChange::class])->group(function () {

    // Change your own password (the only route open while a temporary one is set).
    Route::post('/change-password', [AuthController::class, 'changePassword'])
        ->middleware('throttle:6,1');

    /*
    |--------------------------------------------------------------------------
    | Authentication Test
    |--------------------------------------------------------------------------
    */

    Route::get('/test-auth', function (Request $request) {
        return response()->json([
            'authenticated' => true,
            'user' => $request->user(),
        ]);
    });


    /*
    |--------------------------------------------------------------------------
    | Current User
    |--------------------------------------------------------------------------
    */

    Route::get('/user', function (Request $request) {
        return $request->user();
    });


    /*
    |--------------------------------------------------------------------------
    | Inventory Viewing
    |--------------------------------------------------------------------------
    |
    | Admin, Dean, and Cashier can view inventory.
    |
    */


        Route::get('/items', [ItemController::class, 'index']);
        Route::get('/items/{id}', [ItemController::class, 'show']);



    /*
    |--------------------------------------------------------------------------
    | Request Viewing
    |--------------------------------------------------------------------------
    |
    | Admin, Dean, and Cashier can view requests.
    |
    */


        Route::get('/requests', [RequestController::class, 'index']);
        Route::get('/requests/consumption', [RequestController::class, 'consumption']);
        Route::get('/requests/overdue/mine', [RequestController::class, 'myOverdue']);
        Route::get('/request-notifications', [RequestController::class, 'notifications']);
        Route::post('/request-notifications/read', [RequestController::class, 'readNotifications']);
        Route::get('/requests/coordinator-queue', [RequestController::class, 'coordinatorQueue'])->middleware('role:department_head');
        Route::put('/requests/{id}/forward-to-inventory', [RequestController::class, 'forwardToInventory'])->middleware('role:department_head');
        Route::put('/requests/{id}/confirm-received', [RequestController::class, 'confirmReceived']);
        Route::get('/requests/{id}', [RequestController::class, 'show']);



    /*
    |--------------------------------------------------------------------------
    | Request Creation
    |--------------------------------------------------------------------------
    |
    | Admin and Cashier can create requests.
    |
    | The controller will later determine whether the
    | requested items are allowed for the specific role.
    |
    */

    Route::middleware('role:amo,department_head,admin,instructor,cashier')->group(function () {

        Route::post('/requests', [RequestController::class, 'store']);

        // Borrowers report that they brought an item back (the AMO confirms it).
        Route::post('/requests/items/{id}/report-return', [RequestController::class, 'reportReturn']);

        // Anyone who submits requests needs to see their own limit.
        Route::get('/settings/monthly-request-limit', [SettingController::class, 'monthlyRequestLimit']);
        Route::get('/settings/return-periods', [SettingController::class, 'returnPeriods']);

        // Roadmap #13: anyone can report equipment that needs maintenance or
        // disposal and follow up on their own reports.
        Route::get('/equipment-reports/locations', [EquipmentReportController::class, 'locations']);
        Route::get('/equipment-reports', [EquipmentReportController::class, 'index']);
        Route::post('/equipment-reports', [EquipmentReportController::class, 'store']);

    });

/*
|--------------------------------------------------------------------------
| Uniform Viewing
|--------------------------------------------------------------------------
|
| Admin, Dean, and Cashier can view uniforms.
|
*/

Route::middleware('role:amo,department_head,admin,instructor,cashier')->group(function () {

    Route::get('/uniformtypes', [UniformTypeController::class, 'index']);
    Route::get('/uniformtypes/{id}', [UniformTypeController::class, 'show']);

    Route::get('/uniformvariants', [UniformVariantController::class, 'index']);
    Route::get('/uniformvariants/{id}', [UniformVariantController::class, 'show']);

    Route::middleware('role:amo,cashier')->group(function () {
        Route::get('/uniform-reservations', [UniformReservationController::class, 'index']);
    });
    Route::middleware('role:cashier')->group(function () {
        Route::post('/uniform-reservations', [UniformReservationController::class, 'store']);
        Route::delete('/uniform-reservations/{id}', [UniformReservationController::class, 'cancel']);
    });

});

    /*
    |--------------------------------------------------------------------------
    | ADMIN ONLY
    |--------------------------------------------------------------------------
    */

    Route::middleware('role:amo')->group(function () {

        /*
        | Users
        */

        Route::get('/users', [UserController::class, 'index']);
        Route::get('/users/{id}', [UserController::class, 'show']);
        Route::post('/users', [UserController::class, 'store']);
        Route::put('/users/{id}', [UserController::class, 'update']);
        Route::delete('/users/{id}', [UserController::class, 'destroy']);
        Route::post('/users/{id}/reset-password', [UserController::class, 'resetPassword']);


        /*
        | Account requests (self sign-ups waiting for approval)
        */

        Route::get('/account-requests', [AccountRequestController::class, 'index']);
        Route::post('/account-requests/{id}/approve', [AccountRequestController::class, 'approve']);
        Route::post('/account-requests/{id}/decline', [AccountRequestController::class, 'decline']);


        /*
        | Departments
        */

        Route::get('/departments', [DepartmentController::class, 'index']);
        Route::get('/departments/{id}', [DepartmentController::class, 'show']);
        Route::post('/departments', [DepartmentController::class, 'store']);
        Route::put('/departments/{id}', [DepartmentController::class, 'update']);
        Route::delete('/departments/{id}', [DepartmentController::class, 'destroy']);


        /*
        | Room Loadout (Buildings / Rooms / Room Equipment)
        */

        Route::get('/buildings', [BuildingController::class, 'index']);
        Route::get('/buildings/{id}', [BuildingController::class, 'show']);
        Route::post('/buildings', [BuildingController::class, 'store']);
        Route::put('/buildings/{id}', [BuildingController::class, 'update']);
        Route::delete('/buildings/{id}', [BuildingController::class, 'destroy']);

        Route::get('/rooms', [RoomController::class, 'index']);
        Route::get('/rooms/{id}', [RoomController::class, 'show']);
        Route::post('/rooms', [RoomController::class, 'store']);
        Route::put('/rooms/{id}', [RoomController::class, 'update']);
        Route::delete('/rooms/{id}', [RoomController::class, 'destroy']);

        Route::get('/equipment-distribution/items', [RoomEquipmentController::class, 'overview']);
        Route::get('/rooms/{roomId}/equipment', [RoomEquipmentController::class, 'index']);
        Route::post('/rooms/{roomId}/equipment', [RoomEquipmentController::class, 'store']);
        Route::put('/rooms/{roomId}/equipment/{id}', [RoomEquipmentController::class, 'update']);
        Route::post('/rooms/{roomId}/equipment/{id}/return', [RoomEquipmentController::class, 'returnEquipment']);
        Route::delete('/rooms/{roomId}/equipment/{id}', [RoomEquipmentController::class, 'destroy']);


        /*
        | Inventory Management
        */

        Route::post('/items', [ItemController::class, 'store']);
        Route::put('/items/{id}', [ItemController::class, 'update']);
        Route::delete('/items/{id}', [ItemController::class, 'destroy']);

        Route::put('/items/{id}/restock', [ItemController::class, 'restock']);
        Route::post('/items/{id}/adjust-stock', [ItemController::class, 'adjustStock']);

        /*
        | Low-stock restock workflow (roadmap #12)
        */

        Route::get('/restock/low-stock', [RestockRequestController::class, 'lowStock']);
        Route::get('/restock-requests', [RestockRequestController::class, 'index']);
        Route::post('/restock-requests', [RestockRequestController::class, 'store']);
        Route::put('/restock-requests/{id}/status', [RestockRequestController::class, 'updateStatus']);

        // Roadmap #13: admin handling of equipment reports
        Route::put('/equipment-reports/{id}/send-to-inspection', [EquipmentReportController::class, 'sendToInspection']);
        Route::put('/equipment-reports/{id}/dismiss', [EquipmentReportController::class, 'dismiss']);

        Route::put('/items/{id}/release', [ItemController::class, 'release']);
        Route::put('/items/{id}/borrow', [ItemController::class, 'borrow']);
        Route::put('/items/{id}/return', [ItemController::class, 'returnItem']);


        /*
        | Uniform Types
        */

        Route::post('/uniformtypes', [UniformTypeController::class, 'store']);
        Route::put('/uniformtypes/{id}', [UniformTypeController::class, 'update']);
        Route::delete('/uniformtypes/{id}', [UniformTypeController::class, 'destroy']);


        /*
        | Uniform Variants
        */

        Route::post('/uniformvariants', [UniformVariantController::class, 'store']);
        Route::put('/uniformvariants/{id}', [UniformVariantController::class, 'update']);
        Route::delete('/uniformvariants/{id}', [UniformVariantController::class, 'destroy']);
        Route::put('/uniformvariants/{id}/restock',[UniformVariantController::class, 'restock']);
        Route::put('/uniformvariants/{id}/release',[UniformVariantController::class, 'release']);
        
        /*
        | Request Approval / Rejection
        */

        Route::put('/requests/{id}/approve', [RequestController::class, 'approve']);
        Route::put('/requests/{id}/reject', [RequestController::class, 'reject']);
        Route::post('/requests/{id}/resend-notification', [RequestController::class, 'resendNotification']);
        Route::put('/requests/{id}/release', [RequestController::class, 'release']);


        /*
        | Equipment Return
        */

        Route::put(
            '/requests/items/{id}/return',
            [RequestController::class, 'returnEquipment']
        );

        // Return reports waiting for the AMO to check them.
        Route::get('/return-reports', [RequestController::class, 'returnReports']);
        Route::put('/return-reports/{id}/confirm', [RequestController::class, 'confirmReturnReport']);
        Route::put('/return-reports/{id}/reject', [RequestController::class, 'rejectReturnReport']);


        /*
        | Return Inspections
        */

        Route::get('/nav-counts', [\App\Http\Controllers\NavCountController::class, 'index']);
        Route::get('/inspections', [InspectionController::class, 'index']);
        Route::put('/inspections/{id}', [InspectionController::class, 'inspect']);
        Route::put('/items/{itemId}/return-to-service', [InspectionController::class, 'returnToService']);


        /*
        | Settings
        */

        Route::put('/settings/monthly-request-limit', [SettingController::class, 'updateMonthlyRequestLimit']);
        Route::put('/settings/return-periods', [SettingController::class, 'updateReturnPeriods']);


        /*
        | Activity Logs
        */

        Route::get('/logs', [LogController::class, 'index']);
        Route::get('/logs/users', [LogController::class, 'users']);


        /*
        | Reports
        */

        Route::get('/reports/inventory', [ReportController::class, 'inventory']);
        Route::get('/reports/requests', [ReportController::class, 'requests']);
        Route::get('/reports/logs', [ReportController::class, 'logs']);

    });


    /*
    |--------------------------------------------------------------------------
    | DASHBOARD
    |--------------------------------------------------------------------------
    |
    | All three roles can access the dashboard.
    |
    */

    Route::middleware('role:amo,department_head,admin,instructor,cashier')->group(function () {

        Route::get('/dashboard', [DashboardController::class, 'index']);

    });

});