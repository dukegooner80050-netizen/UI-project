<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

use App\Http\Controllers\AuthController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\DepartmentController;
use App\Http\Controllers\ItemController;
use App\Http\Controllers\UniformTypeController;
use App\Http\Controllers\UniformVariantController;
use App\Http\Controllers\RequestController;
use App\Http\Controllers\LogController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\BuildingController;
use App\Http\Controllers\RoomController;
use App\Http\Controllers\RoomEquipmentController;
use App\Http\Controllers\SettingController;
use App\Http\Controllers\InspectionController;


/*
|--------------------------------------------------------------------------
| Authentication
|--------------------------------------------------------------------------
*/

Route::post('/register', [AuthController::class, 'register']);
Route::post('/login', [AuthController::class, 'login']);

Route::post('/logout', [AuthController::class, 'logout'])
    ->middleware('auth:sanctum');


/*
|--------------------------------------------------------------------------
| Authenticated Users
|--------------------------------------------------------------------------
*/

Route::middleware('auth:sanctum')->group(function () {

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

    Route::middleware('role:admin,dean,cashier')->group(function () {

        Route::post('/requests', [RequestController::class, 'store']);

        // Anyone who submits requests needs to see their own limit.
        Route::get('/settings/monthly-request-limit', [SettingController::class, 'monthlyRequestLimit']);

    });

/*
|--------------------------------------------------------------------------
| Uniform Viewing
|--------------------------------------------------------------------------
|
| Admin, Dean, and Cashier can view uniforms.
|
*/

Route::middleware('role:admin,dean,cashier')->group(function () {

    Route::get('/uniformtypes', [UniformTypeController::class, 'index']);
    Route::get('/uniformtypes/{id}', [UniformTypeController::class, 'show']);

    Route::get('/uniformvariants', [UniformVariantController::class, 'index']);
    Route::get('/uniformvariants/{id}', [UniformVariantController::class, 'show']);

});

    /*
    |--------------------------------------------------------------------------
    | ADMIN ONLY
    |--------------------------------------------------------------------------
    */

    Route::middleware('role:admin')->group(function () {

        /*
        | Users
        */

        Route::get('/users', [UserController::class, 'index']);
        Route::get('/users/{id}', [UserController::class, 'show']);
        Route::post('/users', [UserController::class, 'store']);
        Route::put('/users/{id}', [UserController::class, 'update']);
        Route::delete('/users/{id}', [UserController::class, 'destroy']);


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

        Route::get('/rooms/{roomId}/equipment', [RoomEquipmentController::class, 'index']);
        Route::post('/rooms/{roomId}/equipment', [RoomEquipmentController::class, 'store']);
        Route::put('/rooms/{roomId}/equipment/{id}', [RoomEquipmentController::class, 'update']);
        Route::delete('/rooms/{roomId}/equipment/{id}', [RoomEquipmentController::class, 'destroy']);


        /*
        | Inventory Management
        */

        Route::post('/items', [ItemController::class, 'store']);
        Route::put('/items/{id}', [ItemController::class, 'update']);
        Route::delete('/items/{id}', [ItemController::class, 'destroy']);

        Route::put('/items/{id}/restock', [ItemController::class, 'restock']);
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


        /*
        | Equipment Return
        */

        Route::put(
            '/requests/items/{id}/return',
            [RequestController::class, 'returnEquipment']
        );


        /*
        | Return Inspections
        */

        Route::get('/inspections', [InspectionController::class, 'index']);
        Route::put('/inspections/{id}', [InspectionController::class, 'inspect']);
        Route::put('/items/{itemId}/return-to-service', [InspectionController::class, 'returnToService']);


        /*
        | Settings
        */

        Route::put('/settings/monthly-request-limit', [SettingController::class, 'updateMonthlyRequestLimit']);


        /*
        | Activity Logs
        */

        Route::get('/logs', [LogController::class, 'index']);


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

    Route::middleware('role:admin,dean,cashier')->group(function () {

        Route::get('/dashboard', [DashboardController::class, 'index']);

    });

});