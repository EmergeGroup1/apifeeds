<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

use App\Http\Requests;
use App\Http\Controllers\Controller;
use Input;
use Validator;
use Auth;
use App\User;

class LoginController extends Controller
{
    public function __construct()
    {
      $this->middleware('auth',['except' => ['loginChecker','checker']]);
    }

    /**
     * Display a listing of the resource.
     *
     * @return Response
     */
    public function index()
    {
        //
    }

    /**
     * Show the form for creating a new resource.
     *
     * @return Response
     */
    public function create()
    {
        //
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param  Request  $request
     * @return Response
     */
    public function store(Request $request)
    {
        //
    }

    /**
     * Display the specified resource.
     *
     * @param  int  $id
     * @return Response
     */
    public function show($id)
    {
        //
    }

    /**
     * Show the form for editing the specified resource.
     *
     * @param  int  $id
     * @return Response
     */
    public function edit($id)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     *
     * @param  Request  $request
     * @param  int  $id
     * @return Response
     */
    public function update(Request $request, $id)
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  int  $id
     * @return Response
     */
    public function destroy($id)
    {
        //
    }

  	public function checker()
  	{
  		$username = Input::get('username');
  		$user = User::select('type_id')->where('username',$username)->get()->toArray();
      if($user == NULL){
          return 1;
      }
  		$type = $user[0]['type_id'];

  		// success
  		if($type == 0){
  			return 0;
  		}

  		//fail
  		return 1;

  	}

    /*
    * loginChecker()
    * login checker for the new UI
    */
    public function loginChecker($username,$password)
    {
        $output = array();
        if($username == NULL || $password == NULL){

        }

        if (Auth::attempt(['username' => $username, 'password' => $password])) {
            // generate 25 digit random alphanumeric string
            if(session('token') != NULL){
              $token = session('token');
            } else {
              $token_string = str_random(25);
              session(['token'=>$token_string]);
              $token = session('token');
            }

            $user = User::select('type_id')->where('username',$username)->get()->toArray();
        		$type = $user[0]['type_id'];

            $output = array(
              'user_id' =>  Auth::id(),
              'user_type' =>  $type,
              'err'  =>  0,
              'msg'  =>  'Successfully Logged In',
              'token' =>  $token
            );

        } else {

            session(['token'=>NULL]);
            $output = array(
              'err'  =>  1,
              'msg'  =>  'Username and Password did not match. Please try again'
            );

        }

        return $output;

    }
}
