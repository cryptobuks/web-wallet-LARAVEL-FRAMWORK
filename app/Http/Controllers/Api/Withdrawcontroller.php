<?php

namespace App\Http\Controllers\Api;

use Illuminate\Http\Request;
use App\User;
use App\Broker;
use App\Coin;
use App\Coin_address;
use App\Users_2;
use App\User_coin_2;
use App\User_balance_2;
use App\Http\Requests;
use Tymon\JWTAuth\JWTAuth;
use App\Http\Controllers\Controller;
use Tymon\JWTAuth\Exceptions\JWTException;
use Curl\Curl;
use Validator;

class Withdrawcontroller extends Controller
{
	//
	private $user;
	private $users_2;
	private $coin;
	private $user_coin_2;
	private $users_balance_2;
	private $coin_address;
	private $jwtauth;

	public function __construct(User $user, Users_2 $users_2, JWTAuth $jwtauth, Coin_address $coin_address, Coin $coin, User_coin_2 $user_coin_2, User_balance_2 $users_balance_2){
		$this->user = $user;
		$this->jwtauth = $jwtauth;
		$this->coin = $coin;
		$this->user_coin_2 = $user_coin_2;
		$this->users_balance_2 = $users_balance_2;
		$this->users_2 = $users_2;
		$this->coin_address = $coin_address;
	}

	public function decoder_bhai($code){
		$first = base64_decode($code);
		$second = explode("_", $first);
		if(sizeof($second) == 3){
			$third = $second[0].$second[2];
			$fourth = base64_decode($third);
			$fifth = explode("_", $fourth);
			return $fifth[0];
		}
		else{
			return "wrong";
		}
	}

	/*-- Withdrawal Request --*/
	public function Withdraw_request(Request $request){
		$user = $this->jwtauth->parseToken()->authenticate();
		if($user->is_verified == 1 && $user->id == 1){
			$request->validate([
				'coin' => 'required',
				'userid' => 'required|numeric',
				'username' => 'required',
				'message' => '',
				'amount' => 'required|numeric',
				'withdraw_address' => 'required'
			]);
			$bid = $user->id;
	    $exchange_bal = "users_balance_".$bid;
	    if(isset($this->$exchange) && isset($this->$exchange_bal)){
	      $user_exists = $this->$exchange->where(['broker_id'=>$bid,'id'=>$request->userid,'username'=>$request->username])->whereIn('verified',[2,3,4])->get(['id','username','email']);
	      $coin_name =$request->coin;
	      $user_balance = $this->$exchange_bal->where(['account_id'=>$request->userid,'account_name'=>$request->username])->whereIn('currency',[strtolower($coin_name),strtoupper($coin_name)])->get(['currency','balances']);
	      if($user_exists == '[]' || $user_balance == '[]'){
	        return response()->json(['success'=> false, 'message'=> 'User Not Exists or Not Verified']);
	      }
	      else{
	        $user_balance = json_decode($user_balance,true);
	        $coin = $this->coin->where(['broker_id'=>$bid,'coin'=>$request->coin])->get(['id','broker_id','broker_username','coin','min_withdraw','withdraw_fees','api','withdrawals','is_auto']);
	        if($coin == '[]'){
	          return response()->json(['success'=> false, 'message'=> 'Coin Not Found']);
	        }
	        else{
	          $coin_data = json_decode($coin,true);
	          if($coin_data[0]['withdrawals'] == 0 || is_null($coin_data[0]['api'])){
	            return response()->json(['success'=> false, 'message'=> 'Withdrawal Disabled']);
	          }
	          else{
	            $with_time = $this->withdraw_request->where(['userid'=>$request->userid,'username'=>$request->username])->orderBy('created_at', 'desc')->first(['created_at']);
	            if($with_time == '[]' || empty($with_time)){
	              $time_strict = "allowed";
	            }
	            elseif(isset($with_time->created_at) && strtotime($with_time->created_at)+240 < time()){
	              $time_strict = "allowed";
	            }
	            elseif(isset($with_time->created_at) && strtotime($with_time->created_at)+240 > time()){
	              $time_strict = "restrict";
	            }
	            if(isset($time_strict) && $time_strict == "allowed"){
	              if($user_balance[0]['balances'] >= $request->amount+$coin_data[0]['withdraw_fees']){
	                if($coin_data[0]['min_withdraw'] <= $request->amount){
	                  $validate_url = $coin_data[0]['api'].'valid_add_'.$bid;
	                  $validate = new Curl();
	                  $auth_token =  "Bearer ".$this->jwtauth->fromUser($user);
	                  $validate->setHeader('Content-Type','application/json');
	                  $validate->setHeader('Accept','application/json');
	                  $validate->setHeader("Authorization",$auth_token);
	                  $validate->post($validate_url, array(
	                    'key'=>md5('affan'),
	                    'coinid'=>$coin_data[0]['id'],
	                    'coin'=>$coin_data[0]['coin'],
	                    'address'=>$request->withdraw_address
	                  ));
	                  if($validate->error){
	                    return response()->json(['success'=> false, 'message'=> 'Request Failed']);
	                  }
	                  else{
	                    $validate_address = $validate->response;
	                    if(isset($validate_address->isvalid)){
	                      if($validate_address->isvalid == true){
	                        $maxid = ($this->withdraw_request->max('id'));
	                        $maxid = $maxid + 1;
	                        $code = md5($maxid * $request->userid);
	                        $feees = $coin_data[0]['withdraw_fees'];
	                        if($coin_data[0]['is_auto'] == 1){
	                          $check = $this->withdraw_auto($request->coin,$coin_data[0]['id'],$request->amount,$request->withdraw_address,$request->message,$auth_token,$bid,$coin_data[0]['api'],$coin_data[0]['withdraw_fees']);
	                          $check = json_encode($check);
	                          $check = json_decode($check,true);
	                          if($check['original']['success'] == true){
	                            $added = $this->withdraw_request->create(['broker_id'=>$bid,'broker_username'=>$user->username,'coin'=>$request->coin,'coin_id'=>$coin_data[0]['id'],'amount'=>$request->amount,'details'=>$check['original']['data'],'withdraw_address'=>$request->withdraw_address,'message'=>$request->message,'userid'=>$request->userid,'username'=>$request->username,'fees'=>$feees,'auth_code'=>$code,'status'=>3,'category'=>'auto','is_processed'=>1]);
	                            if(!$added){
	                              return response()->json(['success'=> false, 'message'=> 'Withdrawal Request Failed']);
	                            }
	                            else{
	                              return response()->json([$added,'success'=>true]);
	                            }
	                          }
	                          else{
	                            $added = $this->withdraw_request->create(['broker_id'=>$bid,'broker_username'=>$user->username,'coin'=>$request->coin,'coin_id'=>$coin_data[0]['id'],'amount'=>$request->amount,'withdraw_address'=>$request->withdraw_address,'message'=>$request->message,'userid'=>$request->userid,'username'=>$request->username,'fees'=>$feees,'auth_code'=>$code,'status'=>1,'category'=>'auto','status'=>0,"details"=>""]);
	                            if(!$added){
	                              return response()->json(['success'=> false, 'message'=> 'Withdrawal Request Failed']);
	                            }
	                            else{
	                              return response()->json([$added,'success'=>true]);
	                            }
	                          }
	                        }
	                        else{
	                          $added = $this->withdraw_request->create(['broker_id'=>$bid,'broker_username'=>$user->username,'coin'=>$request->coin,'coin_id'=>$coin_data[0]['id'],'amount'=>$request->amount,'withdraw_address'=>$request->withdraw_address,'message'=>$request->message,'userid'=>$request->userid,'username'=>$request->username,'fees'=>$feees,'auth_code'=>$code,"details"=>"","status"=>0]);
	                          if(!$added){
	                            return response()->json(['success'=> false, 'message'=> 'Withdrawal Request Failed']);
	                          }
	                          else{
	                            return response()->json([$added,'success'=>true]);
	                          }
	                        }
	                      }
	                      else{
	                        return response()->json(['success'=> false, 'message'=> 'Invalid Address']);
	                      }
	                    }
	                    else{
	                      return response()->json(['success'=> false, 'message'=> 'Request Failed', 'error'=>$validate_address]);
	                    }
	                  }
	                }
	                else{
	                  return response()->json(['success'=> false, 'message'=> 'Minimum Withrawal limit is '.$coin_data[0]['min_withdraw']]);
	                }
	              }
	              else{
	                return response()->json(['success'=> false, 'message'=> 'Not Enough Balance']);
	              }
	            }
	            else{
	              return response()->json(['success'=> false, 'message'=>'Withdraw Time Exceed']);
	            }
	          }
	        }
	      }
	    }
	    else{
	      return response()->json(['success'=> false, 'message'=> 'Exchange Not Found.']);
	    }
	  }
	  else{
	    return response()->json(['success'=> false, 'message'=> 'Access Denied']);
	  }
	}

}
