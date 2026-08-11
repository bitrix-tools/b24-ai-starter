import requests
import urllib.parse
from datetime import datetime, timedelta, timezone
from typing import Optional, Dict, Any


OAUTH_SCOPE = "accounts balances customers statements sbp payments"

CONSENT_PERMISSIONS = [
    "ReadAccountsBasic",
    "ReadAccountsDetail",
    "ReadBalances",
    "ReadStatements",
    "ReadCustomerData",
    "MakeAcquiringOperation",
    "ReadAcquiringData",
]


class TochkaClient:
    BASE_URL = "https://enter.tochka.com/uapi"
    SANDBOX_URL = "https://enter.tochka.com/sandbox/v2"
    AUTH_URL = "https://enter.tochka.com/connect/token"
    AUTHORIZE_URL = "https://enter.tochka.com/connect/authorize"
    SANDBOX_TOKEN = "sandbox.jwt.token"

    def __init__(
        self,
        client_id: str,
        client_secret: str,
        access_token: Optional[str] = None,
        refresh_token: Optional[str] = None,
        token_expires_at: Optional[datetime] = None,
        sandbox: bool = False,
    ):
        self.client_id = client_id
        self.client_secret = client_secret
        self.access_token = access_token
        self.refresh_token = refresh_token
        self.token_expires_at = token_expires_at
        self.sandbox = sandbox

    def get_client_credentials_token(self) -> str:
        """Get token for creating consent (client_credentials)"""
        response = requests.post(
            self.AUTH_URL,
            data={
                "grant_type": "client_credentials",
                "scope": OAUTH_SCOPE,
                "client_id": self.client_id,
                "client_secret": self.client_secret,
            },
            headers={"Content-Type": "application/x-www-form-urlencoded"},
            timeout=30,
        )
        response.raise_for_status()
        data = response.json()
        return data.get("access_token")

    def create_consent(self) -> str:
        """Create consent and return consent_id"""
        token = self.get_client_credentials_token()
        response = requests.post(
            f"{self.BASE_URL}/v1.0/consents",
            headers={
                "Authorization": f"Bearer {token}",
                "Content-Type": "application/json",
            },
            json={
                "Data": {
                    "permissions": CONSENT_PERMISSIONS,
                    "expirationDateTime": "2030-12-31T23:59:59+00:00",
                }
            },
            timeout=30,
        )
        response.raise_for_status()
        data = response.json()
        return data["Data"]["consentId"]

    def get_authorization_url(self, redirect_uri: str, state: str, consent_id: str) -> str:
        """Generate OAuth authorization URL for user redirect"""
        params = {
            "client_id": self.client_id,
            "response_type": "code",
            "redirect_uri": redirect_uri,
            "scope": OAUTH_SCOPE,
            "state": state,
            "consent_id": consent_id,
        }
        return f"{self.AUTHORIZE_URL}?{urllib.parse.urlencode(params)}"

    def _is_token_valid(self) -> bool:
        if not self.access_token or not self.token_expires_at:
            return False
        expires_at = self.token_expires_at
        if expires_at.tzinfo is None:
            expires_at = expires_at.replace(tzinfo=timezone.utc)
        return datetime.now(timezone.utc) < expires_at - timedelta(minutes=5)

    def exchange_code_for_token(self, code: str, redirect_uri: str) -> Dict[str, Any]:
        """Exchange authorization code for access token"""
        response = requests.post(
            self.AUTH_URL,
            data={
                "grant_type": "authorization_code",
                "code": code,
                "redirect_uri": redirect_uri,
                "scope": OAUTH_SCOPE,
                "client_id": self.client_id,
                "client_secret": self.client_secret,
            },
            headers={"Content-Type": "application/x-www-form-urlencoded"},
            timeout=30,
        )
        response.raise_for_status()
        data = response.json()

        self.access_token = data.get("access_token")
        self.refresh_token = data.get("refresh_token")
        expires_in = data.get("expires_in", 3600)
        self.token_expires_at = datetime.now(timezone.utc) + timedelta(seconds=expires_in)

        return {
            "access_token": self.access_token,
            "refresh_token": self.refresh_token,
            "expires_in": expires_in,
            "token_expires_at": self.token_expires_at,
        }

    def refresh_access_token(self) -> Dict[str, Any]:
        """Refresh access token using refresh token"""
        if not self.refresh_token:
            raise Exception("No refresh token available. Re-authorization required.")

        response = requests.post(
            self.AUTH_URL,
            data={
                "grant_type": "refresh_token",
                "refresh_token": self.refresh_token,
                "client_id": self.client_id,
                "client_secret": self.client_secret,
            },
            headers={"Content-Type": "application/x-www-form-urlencoded"},
            timeout=30,
        )
        
        if not response.ok:
            error_text = response.text
            print(f"Token refresh failed: {response.status_code} - {error_text}")
            raise Exception(f"Token refresh failed: {response.status_code}. Re-authorization required.")
        
        data = response.json()

        self.access_token = data.get("access_token")
        self.refresh_token = data.get("refresh_token", self.refresh_token)
        expires_in = data.get("expires_in", 3600)
        self.token_expires_at = datetime.now(timezone.utc) + timedelta(seconds=expires_in)

        return {
            "access_token": self.access_token,
            "refresh_token": self.refresh_token,
            "expires_in": expires_in,
            "token_expires_at": self.token_expires_at,
        }

    def ensure_token(self) -> str:
        """Ensure we have a valid token, refresh if needed"""
        if not self._is_token_valid():
            if self.refresh_token:
                self.refresh_access_token()
            else:
                raise Exception("Token expired and no refresh token available. Re-authorization required.")
        return self.access_token

    def _headers(self) -> Dict[str, str]:
        if self.sandbox:
            return {
                "Authorization": f"Bearer {self.SANDBOX_TOKEN}",
                "Content-Type": "application/json",
            }
        token = self.ensure_token()
        return {
            "Authorization": f"Bearer {token}",
            "Content-Type": "application/json",
        }

    def _request(self, method: str, endpoint: str, **kwargs) -> Dict[str, Any]:
        base = self.SANDBOX_URL if self.sandbox else self.BASE_URL
        url = f"{base}{endpoint}"
        response = requests.request(
            method, url, headers=self._headers(), timeout=30, **kwargs
        )

        if response.status_code == 401 and self.refresh_token and not self.sandbox:
            self.refresh_access_token()
            response = requests.request(
                method, url, headers=self._headers(), timeout=30, **kwargs
            )

        if not response.ok:
            error_text = response.text
            raise Exception(f"Tochka API error: {response.status_code} - {error_text}")

        return response.json()

    def get_accounts(self) -> Dict[str, Any]:
        """Get list of accounts"""
        return self._request("GET", "/open-banking/v1.0/accounts")

    def get_balances(self, account_id: str) -> Dict[str, Any]:
        """Get account balances"""
        return self._request("GET", f"/open-banking/v1.0/accounts/{account_id}/balances")

    def create_statement(
        self, account_id: str, start_date: str, end_date: str
    ) -> Dict[str, Any]:
        """Create statement request (Init Statement)"""
        return self._request(
            "POST",
            "/open-banking/v1.0/statements",
            json={
                "Data": {
                    "Statement": {
                        "accountId": account_id,
                        "startDateTime": start_date,
                        "endDateTime": end_date,
                    }
                }
            },
        )

    def get_statement(self, account_id: str, statement_id: str) -> Dict[str, Any]:
        """Get statement by ID"""
        return self._request("GET", f"/open-banking/v1.0/accounts/{account_id}/statements/{statement_id}")

    def get_customer(self) -> Dict[str, Any]:
        """Get customers list"""
        return self._request("GET", "/open-banking/v1.0/customers")

    def get_acquiring_retailers(self, customer_code: str) -> Dict[str, Any]:
        """Internet acquiring: list retailers (status REG + isActive for ready)."""
        return self._request("GET", f"/acquiring/v1.0/retailers?customerCode={customer_code}")

    def create_acquiring_payment(self, payload: Dict[str, Any]) -> Dict[str, Any]:
        """Create payment link (Create Payment Operation). payload is inner Data fields."""
        return self._request(
            "POST",
            "/acquiring/v1.0/payments",
            json={"Data": payload},
        )

    def get_acquiring_payment(self, operation_id: str) -> Dict[str, Any]:
        """Get acquiring payment operation status by operationId."""
        return self._request("GET", f"/acquiring/v1.0/payments/{operation_id}")

    def refund_acquiring_payment(
        self,
        operation_id: str,
        *,
        amount: Optional[float] = None,
    ) -> Dict[str, Any]:
        """Refund payment link operation (Tochka: APPROVED / paid). Optional partial amount."""
        oid = str(operation_id).strip()
        data: Dict[str, Any] = {}
        if amount is not None:
            data["amount"] = float(amount)
        return self._request(
            "POST",
            f"/acquiring/v1.0/payments/{oid}/refund",
            json={"Data": data},
        )

    def sync_statement(self, account_id: str, start_date: str, end_date: str, max_retries: int = 15) -> list:
        """Create statement and poll until ready, return transactions"""
        import time

        init_resp = self.create_statement(account_id, start_date, end_date)
        stmt_data = init_resp.get("Data", {}).get("Statement", {})
        statement_id = stmt_data.get("statementId")
        if not statement_id:
            raise Exception(f"No statementId in response: {init_resp}")

        print(f"Created statement {statement_id} for {account_id}")

        for attempt in range(max_retries):
            time.sleep(3)
            stmt = self.get_statement(account_id, statement_id)
            
            statement_data = stmt.get("Data", {})
            statement_list = statement_data.get("Statement", [])
            
            # Debug output
            print(f"Statement {statement_id} attempt {attempt}:")
            print(f"  Statement type={type(statement_list).__name__}, len={len(statement_list) if hasattr(statement_list, '__len__') else 'N/A'}")
            
            if isinstance(statement_list, list) and len(statement_list) > 0:
                st = statement_list[0]
                # API uses "status" or "statementStatus" depending on version
                st_status = st.get("status", "") or st.get("statementStatus", "")
                tx = st.get("Transaction", [])
                tx_count = len(tx)
                print(f"  Status={st_status}, tx_count={tx_count}")
                
                # If we have transactions, consider it ready (some API versions don't set status)
                if st_status.lower() in ("ready", "") and tx_count > 0:
                    print(f"  -> Returning {tx_count} transactions")
                    return tx
                if st_status.lower() in ("error", "rejected"):
                    raise Exception(f"Statement error: {st}")
            elif isinstance(statement_list, dict):
                st_status = statement_list.get("statementStatus", "")
                tx_count = len(statement_list.get("Transaction", []))
                print(f"  [dict] Status={st_status}, tx_count={tx_count}")
                if st_status == "Ready":
                    return statement_list.get("Transaction", [])
            else:
                # Print first 500 chars of statement_list for debugging
                print(f"  [other] value={str(statement_list)[:500]}")

        raise Exception("Statement not ready after polling")
