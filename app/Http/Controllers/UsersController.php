<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

use Validator;
use App\User;
use DB;
use Cache;
use App\UserType;

class UsersController extends Controller
{


      /**
       * Display a listing of the resource.
       *
       * @return Response
       */
      public function apiList()
      {
  				Cache::forget('drivers');
  	    	$users = DB::table('feeds_user_accounts')
  						->leftJoin('feeds_user_type', 'feeds_user_accounts.type_id', '=','feeds_user_type.type_id')
  						->leftJoin('feeds_user_info', 'feeds_user_accounts.id', '=','feeds_user_info.user_id')
  						->select('feeds_user_accounts.id AS user_id',
  										'feeds_user_accounts.username',
  										'feeds_user_accounts.no_hash AS password',
  										'feeds_user_accounts.type_id',
  										'feeds_user_accounts.email',
  										'feeds_user_accounts.created_at',
  										'feeds_user_type.description',
  										'feeds_user_info.first_name',
  										'feeds_user_info.last_name',
  										'feeds_user_info.contact_number')
  						->orderBy('feeds_user_accounts.created_at','desc')
  						->get();

  				foreach($users as $val){
  	        $val->created_at = date("M-d-Y H:i a",strtotime($val->created_at));
  	      }

          return $users;
      }

  		/**
       * Display a listing of the resource.
       *
       * @return Response
       */
      public function apiAdd($data)
      {

  				$validation = Validator::make($data, [
  						'username' => 'required|max:100|regex:/^\S*$/u|unique:feeds_user_accounts,username,',
  						'password' => 'required|min:6',
  						'email' => 'required|email|max:100|unique:feeds_user_accounts,email,',
  						'first_name'	=>	'required',
  						'last_name'	=>	'required',
  						'contact_number' => 'required|numeric',
  						'type' => 'required|integer',
  				]);

  				if($validation->fails()){
  					return array(
  						'err' => 1,
  						'msg' => $validation->errors()->all()
  					);
  				}

  				Cache::forget('drivers');
  				DB::table('feeds_user_accounts')->insert([
  					'username'	=>	$data['username'],
  					'email'		=>	$data['email'],
  					'password'	=>	bcrypt($data['password']),
  					'no_hash'	=>	$data['password'],
  					'type_id'	=>	$data['type'],
  					'created_at'	=>	date("Y-m-d H:i:s"),
  					'updated_at'	=> date("Y-m-d H:i:s")
  				]);

  				// fetch the latest data inserted
  				$user_id = User::orderBy('id','desc')->select('id')->first();

  				// insert data in users type table
  				DB::table('feeds_user_info')->insert([
  					'first_name'	=>	$data['first_name'],
  					'last_name'	=>	$data['last_name'],
  					'contact_number' =>	$data['contact_number'],
  					'user_id'	=>	$user_id->id
  				]);

  				return array(
  													"data"    =>  $data,
  													"err"     =>  0,
  													"msg"     =>  "Successfully added user data"
  													);

      }

  		/**
       * Display a listing of the resource.
       *
       * @return Response
       */
      public function apiUpdate($data)
      {

  				$validation = Validator::make($data, [
  						'username' => 'required|max:100|regex:/^\S*$/u|unique:feeds_user_accounts,username,'.$data['user_id'],
  						'email' => 'required|email|max:100|unique:feeds_user_accounts,email,'.$data['user_id'],
  						'pass' => 'required|min:6',
  						'contact_number' => 'required|numeric',
  						'first_name'	=>	'required',
  						'last_name'	=>	'required',
  						'type_id' => 'required|integer',
  				]);

  				if($validation->fails()){
  					return array(
  						'err' => 1,
  						'msg' => $validation->errors()->all()
  					);
  				}

  				$user = User::where('id',$data['user_id'])
  								->select('feeds_user_accounts.id AS user_id',
  												'feeds_user_accounts.username',
  												'feeds_user_accounts.no_hash AS pass',
  												'feeds_user_accounts.type_id',
  												'feeds_user_accounts.email',
  												'feeds_user_info.first_name',
  												'feeds_user_info.last_name',
  												'feeds_user_info.contact_number')
  								->leftJoin('feeds_user_info','feeds_user_accounts.id','=','feeds_user_info.user_id')
  								->leftJoin('feeds_user_type','feeds_user_accounts.type_id','=','feeds_user_type.type_id')
  								->get()->toArray();

  				$data['type_id'] = (int)$data['type_id'];
  				$data['user_id'] = (int)$data['user_id'];
  				if($data === $user[0]){
  					return array(
  						'err' => 1,
  						'msg' => "no changes"
  					);
  				}

  				Cache::forget('drivers');
  				DB::table('feeds_user_accounts')->where('id',$data['user_id'])->update([
  					'username'	=>	$data['username'],
  					'email'		=>	$data['email'],
  					'password'	=>	bcrypt($data['pass']),
  					'no_hash'	=>	$data['pass'],
  					'type_id'	=>	$data['type_id'],
  					'updated_at'	=> date("Y-m-d H:i:s")
  				]);

  				// insert data in users type table
  				DB::table('feeds_user_info')->where('user_id',$data['user_id'])->update([
  					'first_name'	=>	$data['first_name'],
  					'last_name'	=>	$data['last_name'],
  					'contact_number' =>	$data['contact_number'],
  					'user_id'	=>	$data['user_id']
  				]);

  				return array(
  													"data"    =>  $data,
  													"err"     =>  0,
  													"msg"     =>  "Successfully updated user data"
  													);

      }

  		/**
       * Remove the specified resource from storage.
       *
       * @param  int  $id
       * @return Response
       */
      public function apiDelete($user_id)
      {
  				Cache::forget('drivers');
  				User::where('id',$user_id)->delete();
  				DB::table('feeds_farm_users')->where('user_id',$user_id)->delete();
  				DB::table('feeds_user_info')->where('user_id',$user_id)->delete();

  				return "deleted";
      }

      /**
       * Show the form for editing the specified resource.
       *
       * @param  int  $id
       * @return Response
       */
      public function edit(User $users)
      {
          return view('users.edit', compact('users'));
      }

      


  		/**
       * Update the role of the specific user.
       *
       * @param  int  $id
       * @return Response
       */
  		public function addRoleUpdate()
  		{
  				if(Request::ajax()) {

  					$data = Input::all();

  					$users = User::findOrFail($data['user_id']);
  					$users->type_id = $data['role'];
  					$users->save();

  					$output = "success";

  				} else {

  					$output = "fail";

  				}

  				return $output;
  		}


}
