#include <WiFiNINA.h>
#include <FlashStorage.h>
#include <ModbusMaster.h>
#include <virtuabotixRTC.h>

// ========== KONFIGURASI WIFI ==========
struct WiFiCredentials {
  char ssid[32];
  char pass[32];
};

FlashStorage(wifiStorage, WiFiCredentials);

char server[] = "monitoring-kenyamanan-termal.my.id"; // Domain website
int port = 80;                    // HTTP port
WiFiClient client;

#include <DHT.h>
#define DHTPIN 0 // Pin untuk sensor DHT11
#define DHTTYPE DHT11
DHT dht(DHTPIN, DHTTYPE);
float humidity, temperature; // Variabel untuk menyimpan kelembapan dan suhu
float cal_humidity, cal_temperature; // Variabel kalibrasi

// Sensor Anemometer
#define EN_RS485 A0
#include <ModbusMaster.h>
ModbusMaster node;
#define SLAVE_ADDR ((uint16_t)0x01)
uint16_t nilai;
float windSpeed; // Variabel untuk kecepatan angin
float cal_windSpeed;

// Sensor MQ135
const int MQ135PIN = A1;  // Pin analog untuk sensor MQ135
float airQuality; // Variabel untuk kualitas udara
float cal_airQuality; // Variabel kalibrasi

// Piezo
int piezoPin = 1;

// Led Hijau
int ledHijau = 2;

// Led Merah
int ledMerah = 3;

// Push button 1
const int buttonPin1 = 4;
bool buttonState1;
bool tampilanAwalSelesai;  // Variabel untuk apakah tampilan awal selesai
bool sistemSiap;

// Push button 2
const int buttonPin2 = 5;
bool buttonState2;
int lastButtonState2 = 0;
bool button2Pressed = false;
bool buatMonitoring;

// Modul RTC
#include <virtuabotixRTC.h>
virtuabotixRTC myRTC(7, 10, A2); //CLK; DAT; RST

// LCD TFT
#include <Adafruit_GFX.h>
#include <Adafruit_ST7735.h>
#include <SPI.h>
#define TFT_CS 11
#define TFT_RST 12
#define TFT_DC 6
Adafruit_ST7735 tft = Adafruit_ST7735(TFT_CS, TFT_DC, TFT_RST);

void setup() {
  Serial.begin(9600);
  Serial1.begin(4800);
  dht.begin();
    
  // Inisialisasi TFT
  tft.initR(INITR_BLACKTAB);
  tft.fillScreen(ST7735_BLACK);
    
  // Setup pin untuk sensor MQ135
  pinMode(MQ135PIN, INPUT);

  // Setup pin untuk sensor anemometer sebagai input
  pinMode(EN_RS485, OUTPUT); // Menetapkan pin RS485 sebagai output
  node.preTransmission(preTransmission); // Mengatur pre-transmission
  node.postTransmission(postTransmission); // Mengatur post-transmission
  node.begin(SLAVE_ADDR, Serial1); // Inisialisasi Modbus dengan alamat slave dan port Serial1

  // Setup pin untuk led dan piezo
  pinMode(piezoPin, OUTPUT);
  pinMode(ledMerah, OUTPUT);
  pinMode(ledHijau, OUTPUT);
  
  // Atur waktu (detik, menit, jam, hari, tanggal, bulan, tahun)
  myRTC.setDS1302Time(00, 10, 7, 6, 25, 05, 2025);
  
  // Koneksi WiFi
  connectToWiFi();
}

void loop() {
    //Memanggil fungsi untuk update data waktu
    myRTC.updateTime();

    // Mode monitoring
    bacaDHT11();
    bacaMQ135(); 
    bacaAnemometer(); 
    sendDataToServer();
    
    // Membaca status push button
    buttonState1 = digitalRead(buttonPin1);
    buttonState2 = digitalRead(buttonPin2);

    if (buttonState1 == HIGH) {
        digitalWrite(ledMerah, LOW);  // Mematikan LED merah
        digitalWrite(ledHijau, LOW); // Mematikan LED hijau
        button2Pressed = false;  // Reset status tombol 2 saat tombol 1 ditekan
        tampilanAwalSelesai = false;
        buatMonitoring = false;
        tampilanAwal();
        tampilanAwalSelesai = true;
        sistemSiap = true;  // Set flag bahwa sistem sudah siap
    }

    if (tampilanAwalSelesai){
       tampilkanTanggalDanWaktu();
    }
    
    if (sistemSiap && buttonState2 == HIGH && lastButtonState2 == LOW) {
       tampilanAwalSelesai = false;
       buatMonitoring = false;
       if (button2Pressed) {
          // Jika tombol sudah ditekan sebelumnya
          tampilkanPesanButton();
          button2Pressed = false; // Reset status
        } else {
          // Jika tombol pertama kali ditekan
          button2Pressed = true; // Set tombol telah ditekan
          tampilanTFT();
          responsLedPeizo();
          buatMonitoring = true;
        }
    }
    // Simpan status tombol sebelumnya untuk deteksi perubahan
    lastButtonState2 = buttonState2;

    // Kirim data setiap 1 detik tanpa delay
    static unsigned long previousMillis = 0;
    const long interval = 1000; // 1 detik

    if (buatMonitoring) {
        unsigned long currentMillis = millis();
        if (currentMillis - previousMillis >= interval) {
            previousMillis += interval;
            tampilanTFT();
            tampilanSerial();
        }
    }
}

// ========== FUNGSI WIFI ==========
void connectToWiFi() {
  auto cred = wifiStorage.read();
  
  if (strlen(cred.ssid) > 0) {
    Serial.print("Attempting to connect to: '");
    Serial.print(cred.ssid);
    Serial.println("'"); 
    
    WiFi.begin(cred.ssid, cred.pass);
    
    for (int i = 0; i < 160; i++) {
      if (WiFi.status() == WL_CONNECTED) {
        Serial.print("Connected! IP: ");
        Serial.println(WiFi.localIP());
        return;
      }
      delay(500);
      Serial.print(".");
    }
    Serial.println("\nConnection timeout!");
    
    // Clear credentials jika gagal
    WiFiCredentials blank = {"", ""};
    wifiStorage.write(blank);
  }
  startConfigPortal();
}

void startConfigPortal() {
  Serial.println("Starting Configuration Portal");
  WiFi.beginAP("ThermalComfort_AP");
  
  Serial.print("AP SSID: ThermalComfort_AP | IP: ");
  Serial.println(WiFi.localIP());
  
  WiFiServer server(80);
  server.begin();

  unsigned long startTime = millis();
  const unsigned long portalTimeout = 180000; // 3 minutes timeout

  while (millis() - startTime < portalTimeout) {
    WiFiClient client = server.available();
    
    if (client) {
      String request = client.readStringUntil('\r');
      
      if (request.indexOf("/configure") != -1) {
        // Capture parameters from URL
        int ssidStart = request.indexOf("ssid=") + 5;
        int ssidEnd = request.indexOf("&", ssidStart);
        int passStart = request.indexOf("pass=") + 5;
        int passEnd = request.indexOf(" ", passStart);
        
        if (ssidStart > 0 && ssidEnd > 0 && passStart > 0 && passEnd > 0) {
          String newSSID = request.substring(ssidStart, ssidEnd);
          newSSID.replace("+", " ");
          String newPass = request.substring(passStart, passEnd);
          // Debug print
          Serial.print("Raw SSID from URL: ");
          Serial.println(request.substring(ssidStart, ssidEnd));
          Serial.print("Decoded SSID: ");
          Serial.println(newSSID);
          Serial.print("Received Pass: ");
          Serial.println(newPass);
          
          // Save to flash
          WiFiCredentials newCred;
          newSSID.toCharArray(newCred.ssid, sizeof(newCred.ssid));
          newPass.toCharArray(newCred.pass, sizeof(newCred.pass));
          
          // Call write without checking return value
          wifiStorage.write(newCred); // Assuming this method does not return a value
          Serial.println("Credentials saved to flash");
        
          // Success response
          client.println("HTTP/1.1 200 OK");
          client.println("Content-Type: text/html");
          client.println();
          client.println("<html><head><meta name=\"viewport\" content=\"width=device-width, initial-scale=1\">");
          client.println("<style>");
          client.println("body { font-family: Arial, sans-serif; background-color: #f5f5f5; margin: 0; padding: 20px; }");
          client.println(".container { max-width: 500px; margin: 0 auto; background: white; padding: 20px; border-radius: 10px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }");
          client.println("h1 { color: #2c3e50; text-align: center; }");
          client.println(".success { color: #27ae60; text-align: center; margin: 20px 0; }");
          client.println("</style></head>");
          client.println("<body><div class='container'>");
          client.println("<h1>Thermal Comfort Device</h1>");
          client.println("<div class='success'>");
          client.println("<h2>Configuration Saved!</h2>");
          client.println("<p>Device will restart automatically</p>");
          client.println("</div></div></body></html>");
          client.stop();
          
          delay(1000);
          Serial.println("Restarting...");
          NVIC_SystemReset(); // Restart board
          return; // Exit function
        }
      }
      
      // Display configuration form
      client.println("HTTP/1.1 200 OK");
      client.println("Content-Type: text/html");
      client.println();
      client.println("<html><head><meta name=\"viewport\" content=\"width=device-width, initial-scale=1\">");
      client.println("<style>");
      client.println("body { font-family: Arial, sans-serif; background-color: #f5f5f5; margin: 0; padding: 20px; }");
      client.println(".container { max-width: 500px; margin: 0 auto; background: white; padding: 20px; border-radius: 10px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }");
      client.println("h1 { color: #2c3e50; text-align: center; }");
      client.println("form { display: flex; flex-direction: column; }");
      client.println("input { margin: 10px 0; padding: 10px; }");
      client.println("button { padding: 10px; background-color: #3498db; color: white; border: none; border-radius: 5px; cursor: pointer; }");
      client.println("</style></head>");
      client.println("<body><div class='container'>");
      client.println("<h1>Configure WiFi</h1>");
      client.println("<form action=\"/configure\" method=\"GET\">");
      client.println("<input type=\"text\" name=\"ssid\" placeholder=\"Enter SSID\" required>");
      client.println("<input type=\"password\" name=\"pass\" placeholder=\"Enter Password\" required>");
      client.println("<button type=\"submit\">Save</button>");
      client.println("</form></div></body></html>");
      client.stop();
    }
  }
  
  // If timeout occurs, disconnect
  Serial.println("Configuration portal timed out");
  WiFi.disconnect(); // Disconnect from the access point
}

// ========== FUNGSI KOMUNIKASI ==========
void sendDataToServer() {
    if (WiFi.status() != WL_CONNECTED) {
        Serial.println("WiFi not connected!");
        connectToWiFi();
        return;
    }

    Serial.println("Preparing to send data...");

    // Bangun URL dengan parameter
    String url = "/insert.php?temperature=" + String(cal_temperature) + 
                 "&humidity=" + String(cal_humidity) + 
                 "&wind_speed=" + String(cal_windSpeed) + 
                 "&air_quality=" + String(cal_airQuality);

    Serial.print("Request URL: ");
    Serial.println(url);

    // Cek nilai parameter
    Serial.print("Temperature: ");
    Serial.println(cal_temperature);
    Serial.print("Humidity: ");
    Serial.println(cal_humidity);
    Serial.print("Wind Speed: ");
    Serial.println(cal_windSpeed);
    Serial.print("Air Quality: ");
    Serial.println(cal_airQuality);

    if (client.connect(server, port)) {
        Serial.println("Connected to server, sending request...");

        // Kirim request HTTP GET dengan format yang benar
        client.println("GET " + url + " HTTP/1.1");
        client.println("Host: monitoring-kenyamanan-termal.my.id");
        client.println("Connection: close");
        client.println("User -Agent: ArduinoWiFi/1.1");
        client.println();  // Baris kosong penting untuk akhir headers

        Serial.println("Request sent, waiting for response...");

        // Tunggu response dengan timeout lebih panjang
        unsigned long startTime = millis();
        while (client.connected() && millis() - startTime < 10000) {
            while (client.available()) {
                String line = client.readStringUntil('\r');
                Serial.println(line); // Tampilkan seluruh response dari server
            }
        }

        client.stop();
        Serial.println("Connection closed");
    } else {
        Serial.println("Connection to server failed!");
        Serial.println("Server: " + String(server));
        Serial.println("Port: " + String(port));
    }
}

String urlEncode(String str) {
  String encodedString = "";
  char c;
  for (int i = 0; i < str.length(); i++) {
    c = str.charAt(i);
    if (isalnum(c) || c == '-' || c == '_' || c == '.' || c == '~') {
      encodedString += c;
    } else if (c == ' ') {
      encodedString += '+';
    } else {
      encodedString += '%';
      encodedString += String(c, HEX);
    }
  }
  return encodedString;
}

// Fungsi untuk membaca DHT11
void bacaDHT11() {
  humidity = dht.readHumidity();
  cal_humidity = (0.888737511 * humidity) + 10.8133515;
  
  temperature = dht.readTemperature();
  cal_temperature = (1.054643195 * temperature) - 1.07173913;
}

// Fungsi untuk membaca MQ135
void bacaMQ135() {
  // Membaca nilai analog dari sensor gas
  airQuality = analogRead(MQ135PIN);
  cal_airQuality = (1.383274232 * airQuality) + 185.430556;
}

void bacaAnemometer() {
  int result = node.readHoldingRegisters(0, 1); // Membaca 1 register dari alamat 0
  if (result == node.ku8MBSuccess) { 
    uint16_t nilai = node.getResponseBuffer(0); // Mengambil nilai dari buffer response
    windSpeed = nilai / 10.0; // Menghitung kecepatan
    cal_windSpeed = (0.998547 * windSpeed) + 0.015543;
  }
}

void preTransmission() {
  digitalWrite(EN_RS485, 1);
}

void postTransmission() {
  digitalWrite(EN_RS485, 0);
}

// Fungsi untuk menampilkan data ke TFT
void tampilanTFT() {
    tft.setRotation(-1);
    tft.fillScreen(ST7735_BLACK); // Membersihkan latar belakang

    // Judul Monitoring
    tft.setTextColor(ST7735_CYAN);
    tft.setTextSize(2);
    tft.setCursor(5, 10);
    tft.println("Monitoring");

    // Garis pemisah
    tft.drawLine(0, 30, 180, 30, ST7735_WHITE); 

    // Tampilkan suhu
    tft.setTextSize(1.2);
    tft.setTextColor(ST7735_GREEN); // Warna hijau untuk suhu
    tft.setCursor(10, 40);
    tft.print("Temp: ");
    tft.print(cal_temperature);
    tft.println(" C");

    // Tampilkan kelembapan
    tft.setTextColor(ST7735_YELLOW); // Warna kuning untuk kelembapan
    tft.setCursor(10, 60);
    tft.print("Hum: ");
    tft.print(cal_humidity);
    tft.println(" %");

    // Tampilkan kecepatan angin
    tft.setTextColor(ST7735_RED); // Warna merah untuk kecepatan angin
    tft.setCursor(10, 80);
    tft.print("Wind: ");
    tft.print(cal_windSpeed);
    tft.println(" m/s");

    // Tampilkan kualitas udara
    tft.setTextColor(ST7735_MAGENTA); // Warna magenta untuk kualitas udara
    tft.setCursor(10, 100);
    tft.print("Air Q: ");
    tft.print(cal_airQuality);
    tft.println(" ppm");

    // Garis pemisah
    tft.drawLine(0, 120, 180, 120, ST7735_WHITE); 
}

// Fungsi untuk menampilkan data ke Serial Monitor
void tampilanSerial() {
    Serial.print("Temperature = ");
    Serial.print(cal_temperature);
    Serial.println(" °C");
    Serial.print("Humidity = ");
    Serial.print(cal_humidity);
    Serial.println(" %");
    Serial.print("Air Quality = ");
    Serial.println(cal_airQuality);
    Serial.print("Wind Speed: ");
    Serial.print(windSpeed);
    Serial.println(" m/s");
   
    Serial.print("Current Date / Time: ");
    Serial.print(myRTC.dayofmonth);
    Serial.print("/");
    Serial.print(myRTC.month);
    Serial.print("/");
    Serial.print(myRTC.year);;
    Serial.print("  ");
    Serial.print(myRTC.hours); 
    Serial.print(":");
    Serial.print(myRTC.minutes);
    Serial.print(":");
    Serial.println(myRTC.seconds);
    
    Serial.println();
  }

void responsLedPeizo() {
    // untuk suhu
    if ((temperature <= 25.7 && temperature >= 20.5 && humidity <= 60 && humidity >= 40 && windSpeed < 0.15) ||
        (temperature <= 27.1 && temperature >= 25.8 && humidity <= 60 && humidity >= 40 && windSpeed <= 0.25 && windSpeed >= 0.15)) {
        digitalWrite(ledMerah, LOW);  // Mematikan LED merah
        digitalWrite(ledHijau, HIGH); // Menyalakan LED hijau
    } else {
        digitalWrite(ledMerah, HIGH); // Menyalakan LED merah jika kondisi tidak terpenuhi
        digitalWrite(ledHijau, LOW);  // Mematikan LED hijau
    }

    // untuk kelembapan
    if (airQuality < 1000){
      noTone(piezoPin);
    } else {
      tone(piezoPin, 500);
    }
}

void tampilanAwal() {
    tft.setRotation(-1); // Rotasi layar ke posisi horizontal 
    tft.fillScreen(ST7735_BLACK); // Membersihkan latar belakang
    tft.setTextColor(ST7735_CYAN);
    tft.setTextSize(3);
    tft.setCursor(21, 35);
    tft.println("SELAMAT");
    tft.setCursor(21, 65);
    tft.println("DATANG!");
    delay(5000);
}

void tampilkanTanggalDanWaktu() {
    tft.setRotation(-1);
    tft.fillScreen(ST7735_BLACK); // Membersihkan latar belakang
    
    tft.drawLine(0, 30, 180, 30, ST7735_GREEN);
    tft.drawLine(0, 95, 180, 95, ST7735_GREEN);
      
    // Menampilkan tanggal di atas
    String dateString = String(myRTC.month) + "/" + 
                        String(myRTC.dayofmonth) + "/" + 
                        String(myRTC.year);
    tft.setTextSize(2); 
    tft.setCursor(25, 45); 
    tft.setTextColor(ST7735_WHITE);
    tft.println(dateString);
  
    // Menampilkan waktu di bawah
    String timeString = String(myRTC.hours) + ":" + 
                        String(myRTC.minutes) + ":" + 
                        String(myRTC.seconds);
    tft.setTextSize(2); 
    tft.setCursor(25, 70);
    tft.setTextColor(ST7735_CYAN);
    tft.println(timeString);
}

// Fungsi untuk menampilkan teks ketika push button ditekan
void tampilkanPesanButton() {
    // Bersihkan layar dan atur tampilan awal
    tft.fillScreen(ST7735_BLACK);
    tft.setRotation(-1);

    // Judul di bagian atas
    tft.setTextColor(ST7735_CYAN);
    tft.setTextSize(1);
    tft.setCursor(10, 10);
    tft.println("Rekomendasi");

    // Garis pemisah setelah judul
    tft.drawLine(10, 30, 150, 30, ST7735_WHITE);

    // Variabel posisi teks
    int yPosition = 40; // Posisi vertikal awal untuk teks rekomendasi

    // Logika rekomendasi jika suhu < 20,5
    if (cal_temperature < 20.5) {
        tft.setCursor(10, yPosition);
        tft.println("Naikkan suhu");
        yPosition += 10;
        tft.setCursor(10, yPosition);
        tft.println("20.5-27.1 C");
        yPosition += 15;
        if (cal_humidity < 40) {
            tft.setCursor(10, yPosition);
            tft.println("Naikkan kelembapan");
            yPosition += 10;
            tft.setCursor(10, yPosition);
            tft.println("40-60%");
        } else if (cal_humidity > 60) {
            tft.setCursor(10, yPosition);
            tft.println("Turunkan kelembapan");
            yPosition += 10;
            tft.setCursor(10, yPosition);
            tft.println("40-60%");
        }
    } 

    // Logika rekomendasi jika suhu 20,5 - 25,7
    else if (cal_temperature <= 25.7) {
        if (cal_humidity < 40) {
            if (cal_windSpeed < 0.15){
                tft.setCursor(10, yPosition);
                tft.println("Naikkan kelembapan");
                yPosition += 10;
                tft.setCursor(10, yPosition);
                tft.println("40-60%");
                yPosition += 15;
                tft.setCursor(10, yPosition);
                tft.println("Naikkan kelembapan");
                yPosition += 10;
                tft.setCursor(10, yPosition);
                tft.println("40-60%");
          } else {
                tft.setCursor(10, yPosition);
                tft.println("Hindari angin");
                yPosition += 10;
                tft.setCursor(10, yPosition);
                tft.println("≥ 0.15 m/s");
            }
        } else if (cal_humidity <= 60) {
            if (cal_windSpeed < 0.15){
                tft.setCursor(10, yPosition);
                tft.println("Kenyamanan Termal");
                yPosition += 10;
                tft.setCursor(10, yPosition);
                tft.println("Ideal");
            } else {
                tft.setCursor(10, yPosition);
                tft.println("Hindari angin");
                yPosition += 10;
                tft.setCursor(10, yPosition);
                tft.println("≥ 0.15 m/s");
              }
        } else {
            if (cal_windSpeed < 0.15){
                tft.setCursor(10, yPosition);
                tft.println("Turunkan kelembapan");
                yPosition += 10;
                tft.setCursor(10, yPosition);
                tft.println("40-60%");
            } else {
                tft.setCursor(10, yPosition);
                tft.println("Turunkan kelembapan");
                yPosition += 10;
                tft.setCursor(10, yPosition);
                tft.println("40-60%");
                yPosition += 15;
                tft.setCursor(10, yPosition);
                tft.println("Hindari angin");
                yPosition += 10;
                tft.setCursor(10, yPosition);
                tft.println("≥ 0.15 m/s");
              }
        }
    } 

    // Logika rekomendasi jika suhu 25,8 - 27,1
    else if (cal_temperature <= 27.1) {
        if (cal_humidity < 40) {
            if (cal_windSpeed < 0.15){
                tft.setCursor(10, yPosition);
                tft.println("Naikkan kelembapan");
                yPosition += 10;
                tft.setCursor(10, yPosition);
                tft.println("40-60%");
                yPosition += 15;
                tft.setCursor(10, yPosition);
                tft.println("Gunakan angin");
                yPosition += 10;
                tft.setCursor(10, yPosition);
                tft.println("0,15 - 0,25 m/s");
            } else if (cal_windSpeed <= 0.25) {
                tft.setCursor(10, yPosition);
                tft.println("Naikkan kelembapan");
                yPosition += 10;
                tft.setCursor(10, yPosition);
                tft.println("40-60%");
            } else {
                tft.setCursor(10, yPosition);
                tft.println("Naikkan kelembapan");
                yPosition += 10;
                tft.setCursor(10, yPosition);
                tft.println("40-60%");
                yPosition += 15;
                tft.setCursor(10, yPosition);
                tft.println("Hindari angin");
                yPosition += 10;
                tft.setCursor(10, yPosition);
                tft.println("> 0.25 m/s");
              }
        } else if (cal_humidity <= 60) {
            if (cal_windSpeed < 0.15){
                tft.setCursor(10, yPosition);
                tft.println("Gunakan angin");
                yPosition += 10;
                tft.setCursor(10, yPosition);
                tft.println("0,15 - 0,25 m/s"); 
            } else if (cal_windSpeed <= 0.25) {
                tft.setCursor(10, yPosition);
                tft.println("Kenyamanan Termal");
                yPosition += 10;
                tft.setCursor(10, yPosition);
                tft.println("Ideal");
            } else {
                tft.setCursor(10, yPosition);
                tft.println("Hindari angin");
                yPosition += 10;
                tft.setCursor(10, yPosition);
                tft.println("> 0.25 m/s");
              }      
        } else {
            if (cal_windSpeed < 0.15){
                tft.setCursor(10, yPosition);
                tft.println("Turunkan kelembapan");
                yPosition += 10;
                tft.setCursor(10, yPosition);
                tft.println("40-60%");
                yPosition += 15;
                tft.setCursor(10, yPosition);
                tft.println("Gunakan Angin");
                yPosition += 10;
                tft.setCursor(10, yPosition);
                tft.println("0,15 - 0,25 m/s"); 
            } else if (cal_windSpeed <= 0.25) {
                tft.setCursor(10, yPosition);
                tft.println("Turunkan kelembapan");
                yPosition += 10;
                tft.setCursor(10, yPosition);
                tft.println("40-60%");
            } else {
                tft.setCursor(10, yPosition);
                tft.println("Hindari angin");
                yPosition += 10;
                tft.setCursor(10, yPosition);
                tft.println("> 0.25 m/s");
            }
        }
    } 

    // Logika rekomendasi jika suhu > 27,1
    else {
        digitalWrite(ledMerah, HIGH);
        tft.setCursor(10, yPosition);
        tft.println("Turunkan suhu");
        yPosition += 10;
        tft.setCursor(10, yPosition);
        tft.println("20.5-27.1 C");
        yPosition += 15;
        if (cal_humidity < 40) {
            tft.setCursor(10, yPosition);
            tft.println("Naikkan kelembapan");
            yPosition += 10;
            tft.setCursor(10, yPosition);            
            tft.println("40-60%");
        } else {
            tft.setCursor(10, yPosition);
            tft.println("Turunkan kelembapan");
            yPosition += 10;
            tft.setCursor(10, yPosition);
            tft.println("40-60%");
        }
    }

    // Rekomendasi kualitas udara
    yPosition += 20;
    tft.drawLine(10, yPosition, 150, yPosition, ST7735_WHITE);
    yPosition += 10;

    if (cal_airQuality < 1000) {
        tft.setCursor(10, yPosition);
        tft.println("Kualitas udara aman");
    } else {
        tft.setCursor(10, yPosition);
        tft.println("Buka jendela untuk");
        yPosition += 10;
        tft.setCursor(10, yPosition);
        tft.println("sirkulasi udara");
    }

    yPosition += 20;
    tft.drawLine(10, yPosition, 150, yPosition, ST7735_WHITE);
}
