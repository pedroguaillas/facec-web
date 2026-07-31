package main

import (
	"crypto"
	"crypto/rsa"
	"encoding/json"
	"io"
	"log"
	"net/http"
	"os"
	"path/filepath"

	"github.com/beevik/etree"
	xades "github.com/digitalautonomy/goxades_sri"
	dsig "github.com/russellhaering/goxmldsig"
	"golang.org/x/crypto/pkcs12"
)

type SignRequest struct {
	CertPath  string `json:"cert_path"`
	Password  string `json:"password"`
	InputPath string `json:"input_path"`
	OutputDir string `json:"output_dir"`
	Filename  string `json:"filename"`
}

func jsonError(w http.ResponseWriter, msg string, code int) {
	w.Header().Set("Content-Type", "application/json")
	w.WriteHeader(code)
	json.NewEncoder(w).Encode(map[string]string{"detail": msg})
}

func signHandler(w http.ResponseWriter, r *http.Request) {
	if r.Method != http.MethodPost {
		http.Error(w, "Method not allowed", http.StatusMethodNotAllowed)
		return
	}

	body, err := io.ReadAll(r.Body)
	if err != nil {
		jsonError(w, "Error reading request body", http.StatusBadRequest)
		return
	}
	defer r.Body.Close()

	var req SignRequest
	if err := json.Unmarshal(body, &req); err != nil {
		jsonError(w, "Invalid JSON", http.StatusBadRequest)
		return
	}

	// Cargar certificado PKCS12
	certData, err := os.ReadFile(req.CertPath)
	if err != nil {
		jsonError(w, "Certificate not found: "+err.Error(), http.StatusBadRequest)
		return
	}

	privateKey, cert, err := pkcs12.Decode(certData, req.Password)
	if err != nil {
		jsonError(w, "Failed to decode certificate: "+err.Error(), http.StatusBadRequest)
		return
	}

	rsaKey, ok := privateKey.(*rsa.PrivateKey)
	if !ok {
		jsonError(w, "Certificate private key is not RSA", http.StatusBadRequest)
		return
	}

	// Leer XML de entrada
	xmlData, err := os.ReadFile(req.InputPath)
	if err != nil {
		jsonError(w, "Input XML not found: "+err.Error(), http.StatusBadRequest)
		return
	}

	// Parsear XML
	doc := etree.NewDocument()
	if err := doc.ReadFromBytes(xmlData); err != nil {
		jsonError(w, "Invalid XML: "+err.Error(), http.StatusBadRequest)
		return
	}

	// Obtener URI de referencia desde el atributo id del elemento raíz
	referenceURI := ""
	if id := doc.Root().SelectAttrValue("id", ""); id != "" {
		referenceURI = "#" + id
	}

	canonicalizer := dsig.MakeC14N10ExclusiveCanonicalizerWithPrefixList("")

	ctx := &xades.SigningContext{
		DataContext: xades.SignedDataContext{
			Canonicalizer: canonicalizer,
			Hash:          crypto.SHA1,
			ReferenceURI:  referenceURI,
			IsEnveloped:   true,
		},
		PropertiesContext: xades.SignedPropertiesContext{
			Canonicalizer: canonicalizer,
			Hash:          crypto.SHA1,
		},
		Canonicalizer: canonicalizer,
		Hash:          crypto.SHA1,
		KeyStore: xades.MemoryX509KeyStore{
			PrivateKey: rsaKey,
			Cert:       cert,
			CertBinary: cert.Raw,
		},
		EtsiNamespacePrefix: "xades",
	}

	sig, err := xades.CreateSignature(doc.Root(), ctx)
	if err != nil {
		jsonError(w, "Signing failed: "+err.Error(), http.StatusInternalServerError)
		return
	}

	doc.Root().AddChild(sig)

	// Escribir XML firmado
	if err := os.MkdirAll(req.OutputDir, 0755); err != nil {
		jsonError(w, "Failed to create output directory: "+err.Error(), http.StatusInternalServerError)
		return
	}

	outputPath := filepath.Join(req.OutputDir, req.Filename)
	if err := doc.WriteToFile(outputPath); err != nil {
		jsonError(w, "Failed to write output file: "+err.Error(), http.StatusInternalServerError)
		return
	}

	w.Header().Set("Content-Type", "application/json")
	json.NewEncoder(w).Encode(map[string]string{"status": "signed"})
}

func healthHandler(w http.ResponseWriter, r *http.Request) {
	w.Header().Set("Content-Type", "application/json")
	json.NewEncoder(w).Encode(map[string]string{"status": "ok"})
}

func main() {
	http.HandleFunc("/sign", signHandler)
	http.HandleFunc("/health", healthHandler)

	port := ":8080"
	log.Printf("Servidor iniciado en http://0.0.0.0%s", port)
	log.Fatal(http.ListenAndServe(port, nil))
}
