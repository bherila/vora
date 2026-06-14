
function getCsrfToken() {
  const meta = document.querySelector('meta[name="csrf-token"]');
  if (meta) {
    return meta.getAttribute('content');
  }
  return null;
}

// Marks requests as XHR/JSON so Laravel returns JSON (e.g. 422 validation errors)
// rather than an HTML redirect.
const JSON_HEADERS = {
  Accept: 'application/json',
  'X-Requested-With': 'XMLHttpRequest',
}

export const fetchWrapper = {
  get,
  post,
  patch,
  put,
  delete: _delete,
}

function get(url: string) {
  const requestOptions = {
    method: 'GET',
    headers: { ...JSON_HEADERS, 'X-CSRF-TOKEN': getCsrfToken() || '' },
    credentials: 'include' as RequestCredentials,
  }
  return fetch(url, requestOptions).then(handleResponse)
}

function post(url: string, body: any) {
  const isFormData = body instanceof FormData;
  const requestOptions: RequestInit = {
    method: 'POST',
    headers: isFormData
      ? { ...JSON_HEADERS, 'X-CSRF-TOKEN': getCsrfToken() || '' }
      : { ...JSON_HEADERS, 'Content-Type': 'application/json', 'X-CSRF-TOKEN': getCsrfToken() || '' },
    credentials: 'include' as RequestCredentials,
    body: isFormData ? body : JSON.stringify(body),
  }
  return fetch(url, requestOptions).then(handleResponse)
}

function put(url: string, body: any) {
  const requestOptions: RequestInit = {
    method: 'PUT',
    headers: { ...JSON_HEADERS, 'Content-Type': 'application/json', 'X-CSRF-TOKEN': getCsrfToken() || '' },
    credentials: 'include' as RequestCredentials,
    body: JSON.stringify(body),
  }
  return fetch(url, requestOptions).then(handleResponse)
}

function patch(url: string, body: any) {
  const requestOptions: RequestInit = {
    method: 'PATCH',
    headers: { ...JSON_HEADERS, 'Content-Type': 'application/json', 'X-CSRF-TOKEN': getCsrfToken() || '' },
    credentials: 'include' as RequestCredentials,
    body: JSON.stringify(body),
  }
  return fetch(url, requestOptions).then(handleResponse)
}

// prefixed with underscored because delete is a reserved word in javascript
function _delete(url: string, body?: any) {
  const requestOptions: RequestInit = {
    method: 'DELETE',
    headers: { ...JSON_HEADERS, 'Content-Type': 'application/json', 'X-CSRF-TOKEN': getCsrfToken() || '' },
    credentials: 'include',
    body: body === undefined ? null : JSON.stringify(body),
  }
  return fetch(url, requestOptions).then(handleResponse)
}

// helper functions
function handleResponse(response: Response) {
  return response.text().then((text) => {
    let data: any = null
    if (text) {
      try {
        data = JSON.parse(text)
      } catch (e) {
        // response wasn't JSON (could be an HTML redirect to login), keep raw text
        data = text
      }
    }

    if (!response.ok) {
      const error = (data && data.message) || response.statusText
      return Promise.reject(error)
    }

    return data
  })
}
