//! The user's own trusted roots (`CurrentUser\Root`), for the local CA (F6).
//! Adding needs no administrator; Windows asks the user to confirm.

use std::{io, ptr};

use windows_sys::Win32::{
    Foundation::ERROR_CANCELLED,
    Security::Cryptography::{
        CERT_CONTEXT, CERT_FIND_EXISTING, CERT_STORE_ADD_REPLACE_EXISTING, CERT_STORE_PROV_SYSTEM_W,
        CERT_SYSTEM_STORE_CURRENT_USER, CertAddEncodedCertificateToStore, CertCloseStore, CertCreateCertificateContext,
        CertDeleteCertificateFromStore, CertFindCertificateInStore, CertFreeCertificateContext, CertOpenStore,
        HCERTSTORE, PKCS_7_ASN_ENCODING, X509_ASN_ENCODING,
    },
};

const ENCODING: u32 = X509_ASN_ENCODING | PKCS_7_ASN_ENCODING;

struct Store(HCERTSTORE);

impl Drop for Store {
    fn drop(&mut self) {
        // SAFETY: a store this module opened.
        unsafe { CertCloseStore(self.0, 0) };
    }
}

fn user_root() -> Result<Store, String> {
    let name: Vec<u16> = "Root".encode_utf16().chain(Some(0)).collect();
    // SAFETY: the system store provider takes a NUL-terminated wide name.
    let store = unsafe {
        CertOpenStore(CERT_STORE_PROV_SYSTEM_W, 0, 0, CERT_SYSTEM_STORE_CURRENT_USER, name.as_ptr().cast())
    };
    if store.is_null() {
        return Err(format!("cannot open your trusted root certificates: {}", io::Error::last_os_error()));
    }
    Ok(Store(store))
}

/// The certificate in `store` equal to `der`, if any; free it after use.
fn find(store: &Store, der: &[u8]) -> Result<Option<*mut CERT_CONTEXT>, String> {
    // SAFETY: `der` is a whole certificate; the context is freed below.
    let wanted = unsafe { CertCreateCertificateContext(ENCODING, der.as_ptr(), der.len() as u32) };
    if wanted.is_null() {
        return Err(format!("not a certificate: {}", io::Error::last_os_error()));
    }
    // SAFETY: looks for an identical certificate; `wanted` stays valid until freed.
    let found = unsafe { CertFindCertificateInStore(store.0, ENCODING, 0, CERT_FIND_EXISTING, wanted.cast(), ptr::null()) };
    // SAFETY: created above.
    unsafe { CertFreeCertificateContext(wanted) };
    Ok((!found.is_null()).then_some(found))
}

/// `false` when the user declined Windows' confirmation.
pub fn add_user_root(der: &[u8]) -> Result<bool, String> {
    let store = user_root()?;
    // SAFETY: the bytes are a whole certificate; no context is returned.
    let added = unsafe {
        CertAddEncodedCertificateToStore(
            store.0,
            ENCODING,
            der.as_ptr(),
            der.len() as u32,
            CERT_STORE_ADD_REPLACE_EXISTING,
            ptr::null_mut(),
        )
    };
    if added != 0 {
        return Ok(true);
    }
    let error = io::Error::last_os_error();
    if error.raw_os_error() == Some(ERROR_CANCELLED as i32) {
        return Ok(false);
    }
    Err(error.to_string())
}

pub fn remove_user_root(der: &[u8]) -> Result<bool, String> {
    let store = user_root()?;
    let Some(found) = find(&store, der)? else {
        return Ok(false);
    };
    // SAFETY: a context from this store; deleting also frees it.
    if unsafe { CertDeleteCertificateFromStore(found) } == 0 {
        let error = io::Error::last_os_error();
        if error.raw_os_error() == Some(ERROR_CANCELLED as i32) {
            return Ok(false);
        }
        return Err(error.to_string());
    }
    Ok(true)
}

pub fn has_user_root(der: &[u8]) -> Result<bool, String> {
    let store = user_root()?;
    let found = find(&store, der)?;
    if let Some(found) = found {
        // SAFETY: returned by the find above.
        unsafe { CertFreeCertificateContext(found) };
    }
    Ok(found.is_some())
}
