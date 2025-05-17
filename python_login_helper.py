"""
Python Login Helper for PhpWechatAggregator

This script is designed to be called by a PHP script.
It uses WechatRequest from the original project to trigger
the QR code login process.

If login is successful, it prints a JSON string to stdout
containing the new token and cookie.
It also updates the 'data/id_info.json' file as per original behavior.

Requirements:
- Python 3.x
- Access to the 'request_' module and 'util' module from the original project.
- DrissionPage and its dependencies (likely requests, lxml, etc.)
  (pip install DrissionPage)
- A graphical environment for the browser window to appear for QR scanning.
"""
import sys
import json
import os
from pathlib import Path

# --- Dynamically adjust Python path to find original project modules ---
# This assumes 'python_login_helper.py' is in the root of 'PhpWechatAggregator',
# and the original project structure (with 'request_' and 'util' as subdirs or siblings)
# is accessible relative to this script's parent or a known location.

# For this example, let's assume the original project structure is one level up
# and then into a directory named 'WeChatOA_Aggregation_Original'
# You MUST adjust this path to match your actual project structure.

current_script_path = Path(__file__).resolve()
php_wechat_aggregator_root = current_script_path.parent

# Path to the original Python project (where request_/wechat_request.py is)
# **** THIS IS A CRITICAL PATH TO CONFIGURE ****
# Option 1: If PhpWechatAggregator is INSIDE the original project structure
# original_project_root = php_wechat_aggregator_root.parent / 'WeChatOA_Aggregation_Original' # Example
# Option 2: If they are siblings
# original_project_root = php_wechat_aggregator_root.parent / 'WeChatOA_Aggregation_Original'
# Option 3: If the original 'request_' and 'util' folders are copied into PhpWechatAggregator
#           (ensure __init__.py files are present in them if they are packages)
original_project_root = php_wechat_aggregator_root # Assuming 'request_' and 'util' are now subdirs of PhpWechatAggregator

# Add the directory containing 'request_' and 'util' to sys.path
# If 'request_' and 'util' are directly under 'original_project_root':
sys.path.insert(0, str(original_project_root))
# If 'request_' and 'util' are further nested, adjust accordingly.
# e.g., if original_project_root is the parent of 'WeChatOA_Aggregation_Original'
# sys.path.insert(0, str(original_project_root / 'WeChatOA_Aggregation_Original'))


# Attempt to import after path adjustment
try:
    from request_.wechat_request import WechatRequest
    # If util.py contains `handle_json` and `headers` used by WechatRequest implicitly
    # it should be importable by wechat_request.py if it's in the same directory or PYTHONPATH
except ImportError as e:
    print(json.dumps({"success": False, "error": f"ImportError: {e}. Check Python path and module locations."}), file=sys.stderr)
    sys.exit(1)
except Exception as e_imp:
    print(json.dumps({"success": False, "error": f"Unexpected error during import: {e_imp}"}), file=sys.stderr)
    sys.exit(1)

def main():
    """
    Initializes WechatRequest, which should trigger the login process
    if credentials in data/id_info.json are missing or invalid.
    Outputs new token and cookie as JSON to stdout.
    """
    id_info_dir = php_wechat_aggregator_root / 'data'
    id_info_dir.mkdir(parents=True, exist_ok=True)
    
    # ---- START DEBUGGING SECTION ----
    # Forcibly delete id_info.json to ensure login is attempted
    id_info_file_path = id_info_dir / "id_info.json"
    if id_info_file_path.exists():
        try:
            os.remove(id_info_file_path)
            print(f"DEBUG: Successfully removed {id_info_file_path} to force login.", file=sys.stderr)
        except Exception as e_del:
            print(f"DEBUG: Could not remove {id_info_file_path}: {e_del}", file=sys.stderr)
    else:
        print(f"DEBUG: {id_info_file_path} does not exist, login should be triggered.", file=sys.stderr)
    # ---- END DEBUGGING SECTION ----

    try:
        print("DEBUG: About to instantiate WechatRequest.", file=sys.stderr)
        wechat_req = WechatRequest() 
        print("DEBUG: WechatRequest instantiated.", file=sys.stderr)

        # ---- START DEBUGGING SECTION ----
        # Explicitly try to call the login method if it exists, or simulate the check
        # This depends on how WechatRequest is structured.
        # If WechatRequest automatically calls login on init if needed,
        # we might need to see if token/cookie are present *before* any other action.
        
        initial_token = getattr(wechat_req, 'token', None)
        initial_cookie = getattr(wechat_req, 'headers', {}).get('Cookie', None)
        print(f"DEBUG: Token after __init__: {' präsent' if initial_token else 'None'}", file=sys.stderr)
        print(f"DEBUG: Cookie after __init__: {' präsent' if initial_cookie else 'None'}", file=sys.stderr)

        # To be absolutely sure login() is called if credentials are bad:
        if not initial_token or not initial_cookie or initial_token == 'None': # Assuming 'None' string for token might happen
            print("DEBUG: Initial token/cookie are missing/invalid. Attempting to call login() explicitly if available, or rely on next API call to trigger it.", file=sys.stderr)
            if hasattr(wechat_req, 'login') and callable(getattr(wechat_req, 'login')) :
                 print("DEBUG: Explicitly calling wechat_req.login() NOW.", file=sys.stderr)
                 try:
                     wechat_req.login() # Call it directly
                     print("DEBUG: wechat_req.login() executed.", file=sys.stderr)
                 except Exception as e_login_call:
                     print(f"DEBUG: EXCEPTION during explicit wechat_req.login() call: {e_login_call}", file=sys.stderr)
                     import traceback
                     traceback.print_exc(file=sys.stderr)


        # ---- END DEBUGGING SECTION ----

        # The rest of the script remains the same...
        # Attempt to get new_token and new_cookie again
        new_token = getattr(wechat_req, 'token', None)
        new_cookie = getattr(wechat_req, 'headers', {}).get('Cookie', None)
        print(f"DEBUG: Token after potential login call: {'present' if new_token else 'None'}", file=sys.stderr)
        print(f"DEBUG: Cookie after potential login call: {'present'if new_cookie else 'None'}", file=sys.stderr)


        if new_token and new_cookie and new_token != 'None': # 'None' check from original test_login
            result = {
                "success": True,
                "token": new_token,
                "cookie": new_cookie,
                "message": "Login successful. Credentials obtained."
            }
            # The WechatRequest.login() method should have already saved to its id_info.json
            print("Login seems successful. Token and cookie obtained.", file=sys.stderr)
        else:
            # This case might occur if user closes browser, login fails,
            # or if existing credentials in id_info.json were still somehow considered valid
            # by WechatRequest's initial checks but are actually not functional.
            # The crucial part is whether WechatRequest.login() was robustly triggered.
            result = {
                "success": False,
                "error": "Failed to obtain new token and cookie after WechatRequest initialization. Login might have been skipped or failed.",
                "token": new_token, # include what we got, even if None
                "cookie": new_cookie
            }
            print("Failed to get new token/cookie. See error message.", file=sys.stderr)

    except Exception as e:
        import traceback
        error_details = traceback.format_exc()
        result = {
            "success": False,
            "error": f"An exception occurred: {str(e)}",
            "details": error_details
        }
        print(f"Exception in python_login_helper: {str(e)}", file=sys.stderr)
        print(error_details, file=sys.stderr)
    
    # Output result as JSON to stdout for PHP
    print(json.dumps(result))

if __name__ == "__main__":
    main() 