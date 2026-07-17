async function searchProductByBarcode(barcode) {
  try {
    const response = await fetch(`/api/products?barcode=${barcode}`);
    const product = await response.json();
    if (product) {
      displayProductDetails(product);
    } else {
      alert("Product not found!");
    }
  } catch (error) {
    console.error("Error:", error);
  }
}

function displayProductDetails(product) {
  // Update your UI with product details
  console.log("Product found:", product);
  // Example: Fill a form or show a modal
}

import React, { useEffect, useRef } from 'react';

function BarcodeScanner() {
  const barcodeInputRef = useRef(null);

  useEffect(() => {
    const handleBarcodeInput = (e) => {
      const barcode = e.target.value.trim();
      if (barcode.length > 5) {
        searchProductByBarcode(barcode);
        barcodeInputRef.current.value = ''; // Clear the input
      }
    };

    const barcodeInput = barcodeInputRef.current;
    barcodeInput.addEventListener('input', handleBarcodeInput);

    return () => {
      barcodeInput.removeEventListener('input', handleBarcodeInput);
    };
  }, []);

  const searchProductByBarcode = async (barcode) => {
    try {
      const response = await fetch(`/api/products?barcode=${barcode}`);
      const product = await response.json();
      if (product) {
        console.log("Product found:", product);
        // Update your UI here
      } else {
        alert("Product not found!");
      }
    } catch (error) {
      console.error("Error:", error);
    }
  };

  return (
    <input
      type="text"
      ref={barcodeInputRef}
      placeholder="Scan barcode..."
      autoFocus
    />
  );
}

export default BarcodeScanner;
