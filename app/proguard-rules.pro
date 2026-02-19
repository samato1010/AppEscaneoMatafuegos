# Add project specific ProGuard rules here.
# By default, the flags in this file are appended to flags specified
# in the SDK tools.

# Retrofit
-keepattributes Signature
-keepattributes Exceptions
-keep class com.hst.appescaneomatafuegos.EscaneoRequest { *; }
-keep class com.hst.appescaneomatafuegos.EscaneoResponse { *; }
