package jp.meishi.meishi_app

import android.content.Intent
import android.net.Uri
import android.provider.DocumentsContract
import io.flutter.embedding.android.FlutterActivity
import io.flutter.embedding.engine.FlutterEngine
import io.flutter.plugin.common.MethodChannel

/**
 * 外部ストレージ（SDカード含む）への保存。Storage Access Frameworkを使うので、
 * 端末全体のストレージ権限は不要。ユーザーが選んだフォルダにだけ書き込める。
 */
class MainActivity : FlutterActivity() {
    private val channel = "meishi/storage"
    private val pickCode = 4101
    private var pending: MethodChannel.Result? = null

    override fun configureFlutterEngine(flutterEngine: FlutterEngine) {
        super.configureFlutterEngine(flutterEngine)
        MethodChannel(flutterEngine.dartExecutor.binaryMessenger, channel).setMethodCallHandler { call, result ->
            when (call.method) {
                "pickFolder" -> {
                    pending = result
                    val i = Intent(Intent.ACTION_OPEN_DOCUMENT_TREE).addFlags(
                        Intent.FLAG_GRANT_READ_URI_PERMISSION or
                            Intent.FLAG_GRANT_WRITE_URI_PERMISSION or
                            Intent.FLAG_GRANT_PERSISTABLE_URI_PERMISSION
                    )
                    startActivityForResult(i, pickCode)
                }
                "saveFile" -> {
                    try {
                        val tree = Uri.parse(call.argument<String>("tree"))
                        val name = call.argument<String>("name")!!
                        val mime = call.argument<String>("mime") ?: "application/octet-stream"
                        val bytes = call.argument<ByteArray>("bytes")!!
                        val parent = DocumentsContract.buildDocumentUriUsingTree(
                            tree, DocumentsContract.getTreeDocumentId(tree)
                        )
                        val doc = DocumentsContract.createDocument(contentResolver, parent, mime, name)
                            ?: throw IllegalStateException("create failed")
                        contentResolver.openOutputStream(doc)!!.use { it.write(bytes) }
                        result.success(doc.toString())
                    } catch (e: Exception) {
                        result.error("SAVE_FAILED", e.message, null)
                    }
                }
                else -> result.notImplemented()
            }
        }
    }

    @Deprecated("Deprecated in Java")
    override fun onActivityResult(requestCode: Int, resultCode: Int, data: Intent?) {
        super.onActivityResult(requestCode, resultCode, data)
        if (requestCode != pickCode) return
        val uri = data?.data
        if (resultCode == RESULT_OK && uri != null) {
            contentResolver.takePersistableUriPermission(
                uri, Intent.FLAG_GRANT_READ_URI_PERMISSION or Intent.FLAG_GRANT_WRITE_URI_PERMISSION
            )
            pending?.success(uri.toString())
        } else {
            pending?.success(null)
        }
        pending = null
    }
}
